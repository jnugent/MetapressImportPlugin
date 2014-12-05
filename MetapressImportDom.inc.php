<?php

/**
 * @file plugins/importexport/metapress/MetapressImportDom.inc.php
 *
 * Copyright (c) 2013-2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MetapressImportDom
 * @ingroup plugins_importexport_metapress
 *
 * @brief Metapress import/export plugin DOM functions for import
 */

import('lib.pkp.classes.xml.XMLCustomWriter');

class MetapressImportDom {

	function importArticle(&$journal, &$node, &$issue, &$submissionFile, &$errors, &$user) {
		$dependentItems = array();
		$result = MetapressImportDom::handleArticleNode($journal, $node, $issue, $submissionFile, $errors, $user, $dependentItems);
		if (!$result) {
			MetapressImportDom::cleanupFailure ($dependentItems);
		}
		return $result;
	}

	function importIssue(&$journal, &$doc) {
		$errors = array();
		$issue = null;
		$hasErrors = false;
		$volumeNumber = null;
		$issueNumber = null;

		$issueNode = MetapressImportDom::getIssueNode($doc, $volumeNumber, $issueNumber);
		$issueDao =& DAORegistry::getDAO('IssueDAO');

		// short circuit all of this, if the volume number is already in use.
		// If so, return that issue.
		$issues = $issueDao->getPublishedIssuesByNumber($journal->getId(), $volumeNumber, $issueNumber);
		$issues = $issues->toArray();
		if (count($issues) == 1) {
			return $issues[0];
		}

		$issue = new Issue();
		$issue->setJournalId($journal->getId());
		$issue->setCurrent(0);

		if (is_numeric($volumeNumber)) {
			$issue->setVolume($volumeNumber);
		}

		if (is_numeric($issueNumber)) {
			$issue->setNumber($issueNumber);
		}

		$journalSupportedLocales = array_keys($journal->getSupportedLocaleNames()); // => journal locales must be set up before
		$journalPrimaryLocale = $journal->getPrimaryLocale();

		$issueInfoNode = $issueNode->getChildByName('IssueInfo');

		if ($node = $issueInfoNode->getChildByName('IssueNumberBegin')) {
			$issue->setNumber($node->getValue());
		}

		if ($node = $issueInfoNode->getChildByName('IssuePublicationDate')) {
			$coverDateNode = $node->getChildByName('CoverDate');
			$coverDisplayNode = $node->getChildByName('CoverDisplay');

			/* --- Set date published --- */

			$pubYear = $coverDateNode->getAttribute('Year');
			$pubMonth = $coverDateNode->getAttribute('Month');
			$pubDay = $coverDateNode->getAttribute('Day');

			// ensure two digit months and years.
			if (preg_match('/^\d$/', $pubMonth)) { $pubMonth = '0' . $pubMonth; }
			if (preg_match('/^\d$/', $pubDay)) { $pubDay = '0' . $pubDay; }

			$publishedDate = strtotime($pubYear . '-' . $pubMonth . '-' . $pubDay); // Build ISO8601.
			if ($publishedDate === -1) {
				$errors[] = array('plugins.importexport.metapress.import.error.invalidDate', array('value' => $publishedDate));
				if ($cleanupErrors) {
					MetapressImportDom::cleanupFailure ($dependentItems);
				}
				return false;
			} else {
				$issue->setYear($pubYear);
				$issue->setDatePublished($publishedDate);
				$issue->setPublished(1);
			}

			if ($coverDisplayNode) {
				$issue->setShowTitle(1);
				$issue->setTitle($coverDisplayNode->getValue(), $journalPrimaryLocale);
			}
		}

		/* --- Assume Open Access Status initially --- */
		$issue->setAccessStatus(ISSUE_ACCESS_OPEN);

		/* --- All processing that does not require an inserted issue ID
		   --- has been performed by this point. If there were no errors
		   --- then insert the issue and carry on. If there were errors,
		   --- then abort without performing the insertion. */

		if ($hasErrors) {
			$issue = null;
			if ($cleanupErrors) {
				MetapressImportDom::cleanupFailure ($dependentItems);
			}
			return false;
		} else {
			if ($issue->getCurrent()) {
				$issueDao->updateCurrentIssue($journal->getId());
			}
			$issue->setId($issueDao->insertIssue($issue));
			$dependentItems[] = array('issue', $issue);
		}

		/* --- See if any errors occurred since last time we checked.
		   --- If so, delete the created issue and return failure.
		   --- Otherwise, the whole process was successful. */

		if ($hasErrors) {
			$issue = null; // Don't pass back a reference to a dead issue
			if ($cleanupErrors) {
				MetapressImportDom::cleanupFailure ($dependentItems);
			}
			return false;
		}

		$issueDao->updateIssue($issue);
		return $issue;
	}

	/**
	 * Handle the Article node and build the article object found in the XML.
	 * @param Journal $journal
	 * @param DOMNode $doc
	 * @param Issue $issue
	 * @param string $submissionFile
	 * @param array $errors
	 * @param User $user
	 * @param array $dependentItems
	 */
	function handleArticleNode($journal, $doc, $issue, $submissionFile, $errors, $user, $dependentItems) {
		$errors = array();

		$articleNode = MetapressImportDom::getArticleNode($doc);
		if ($articleNode) {

			$journalSupportedLocales = array_keys($journal->getSupportedLocaleNames()); // => journal locales must be set up before
			$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
			$articleDao =& DAORegistry::getDAO('ArticleDAO');

			$article = new Article();
			$article->setLocale($journal->getPrimaryLocale());
			$article->setJournalId($journal->getId());
			$article->setUserId($user->getId());
			$article->setStatus(STATUS_PUBLISHED);
			$article->setSubmissionProgress(0);
			$article->setDateSubmitted(Core::getCurrentDate());
			$article->stampStatusModified();

			// Assign article to the first section for this Journal.
			$sectionDao = DAORegistry::getDAO('SectionDAO');
			$sections = $sectionDao->getJournalSections($journal->getId());
			$sections = $sections->toArray();
			$section = null;
			if (count($sections) > 0) {
				$section = $sections[0];
				$article->setSectionId($section->getId());
			} else {
				$errors[] = array('plugins.importexport.metapress.import.error.noJournalSection');
				$hasErrors = true;
			}

			$articleInfoNode = $articleNode->getChildByName('ArticleInfo');

			if ($articleInfoNode) {

				// Extract the 'Free' Attribute on the ArticleInfo element.  If set to 'No' adjust
				// the access policy on the Issue associated with this article accordingly.

				$articleAccess = $articleInfoNode->getAttribute('Free');
				if ($articleAccess == 'No') {
					$issueDao = DAORegistry::getDAO('IssueDAO');
					$issue->setAccessStatus(ISSUE_ACCESS_SUBSCRIPTION);
					$issueDao->updateIssue($issue);
				}

				// Look for a DOI, and use it if possible.
				$doiNode = $articleInfoNode->getChildByName('ArticleDOI');
				if (($value = $doiNode->getValue()) != '') {
					$anotherArticle = $publishedArticleDao->getPublishedArticleByPubId('publisher-id', $value, $journal->getId());
					if ($anotherArticle) {
						$errors[] = array('plugins.importexport.metapress.import.error.duplicatePublicArticleId', array('otherArticleTitle' => $anotherArticle->getLocalizedTitle()));
						$hasErrors = true;
					} else {
						$article->setStoredPubId('publisher-id', $value);
					}
				}
			}
		} else {
			return null;
		}

		// Handle Pages.
		$firstPageNode = $articleInfoNode->getChildByName('ArticleFirstPage');
		$lastPageNode = $articleInfoNode->getChildByName('ArticleLastPage');

		if ($firstPageNode && $lastPageNode) {
			$article->setPages($firstPageNode->getValue() . '-' . $lastPageNode->getValue());
		} elseif ($firstPageNode) {
			$article->setPages($firstPageNode->getValue());
		}

		$titleExists = false;
		for ($index=0; ($node = $articleInfoNode->getChildByName('ArticleTitle', $index)); $index++) {
			$locale = $node->getAttribute('Language');
			$foundLocale = false;
			if ($locale == '') {
				$foundLocale = true;
				$locale = $article->getLocale();
				$article->setTitle($node->getValue(), $locale);
			} else {
				foreach ($journalSupportedLocales as $journalLocale) {
					// Metapress uses two character locale codes (En, Fr, ... )
					if (preg_match('/^' . preg_quote($locale) . '_\w{2}$/i', $journalLocale)) {
						$article->setTitle($node->getValue(), $journalLocale);
						$foundLocale = true;
					}
				}
			}

			if (!$foundLocale) {
				$errors[] = array('plugins.importexport.metapress.import.error.articleTitleLocaleUnsupported');
				return false;
			}
			$titleExists = true;
		}
		if (!$titleExists || $article->getTitle($article->getLocale()) == '') {
			$errors[] = array('plugins.importexport.metapress.import.error.articleTitleMissing');
			return false;
		}

		// Handle the ArticleHistory node, which contains dates.
		$articleHistoryNode = $articleInfoNode->getChildByName('ArticleHistory');
		$publicationDate = null; // for later, when the PublishedArticle object is created.

		if ($articleHistoryNode) {
			$dateSubmittedNode = $articleHistoryNode->getChildByName('ReceivedDate');
			if ($dateSubmittedNode) {
				$article->setDateSubmitted(strtotime($dateSubmittedNode->getValue()));
			}
			$onlineDateNode = $articleHistoryNode->getChildByName('OnlineDate');
			if ($onlineDateNode) {
				$publicationDate = strtotime($onlineDateNode->getValue());
			}
		}

		$articleHeaderNode = $articleNode->getChildByName('ArticleHeader');
		if ($articleHeaderNode) {
			for ($index=0; ($node = $articleHeaderNode->getChildByName('Abstract', $index)); $index++) {
				$locale = $node->getAttribute('Language');
				$foundLocale = false;

				if ($locale == '') {
					$foundLocale = true;
					$locale = $article->getLocale();
				} else {
					foreach ($journalSupportedLocales as $journalLocale) {
						// Metapress uses two character locale codes (En, Fr, ... )
						if (preg_match('/^' . preg_quote($locale) . '_\w{2}$/i', $journalLocale)) {
							$article->setAbstract($node->getValue(), $journalLocale);
							$foundLocale = true;
						}
					}
				}
			}

			// Determine if there are keywords to use for indexing.
			// Since OJS uses semi-colon separated subjects in OJS 2.4, separate them by locale and
			// then add them to the article accordingly.

			$keywordGroupNode = $articleHeaderNode->getChildByName('KeywordGroup');
			if ($keywordGroupNode) {
				$keywordLocales = array();
				for ($index=0; ($node = $articleHeaderNode->getChildByName('KeywordGroup', $index)); $index++) {
					$locale = $node->getAttribute('Language');

					if ($locale == '') {
						$locale = $article->getLocale();
					} else {
						foreach ($journalSupportedLocales as $journalLocale) {
							// Metapress uses two character locale codes (En, Fr, ... )
							if (preg_match('/^' . preg_quote($locale) . '_\w{2}$/i', $journalLocale)) {
								$keywordNode = $node->getChildByName('Keyword');
								if (array_key_exists($journalLocale, $keywordLocales)) {
									$keywordLocales[$journalLocale] .= ';' . $keywordNode->getValue();
								}
								else {
									$keywordLocales[$journalLocale] = $keywordNode->getValue();
								}
							}
						}
					}
				}
				foreach ($keywordLocales as $locale => $string) {
					$article->setSubject($string, $locale);
				}
			}

			// Handle bibliographic references.
			$biblistNode = $articleHeaderNode->getChildByName('biblist');
			if ($biblistNode) {
				$citations = '';
				for ($index=0; ($bibNode = $biblistNode->getChildByName('bib-other', $index)); $index++) {
					$bibTextNode = $bibNode->getChildByName('bibtext');
					$citations .= $bibTextNode->getValue() . '\n';
				}
				$article->setCitations($citations);
			}
		}

		$articleDao->insertArticle($article);

		$dependentItems[] = array('article', $article);

		/* --- Handle authors --- */
		if ($articleHeaderNode) {
			$authorGroupNode = $articleHeaderNode->getChildByName('AuthorGroup');
			if ($authorGroupNode) {
				$authors = array();
				$affiliations = array();

				for ($index=0; ($node = $authorGroupNode->getChildByName('Author', $index)) ; $index++) {
					$affiliationId = null;
					$author = MetapressImportDom::handleAuthorNode($journal, $node, $article, $affiliationId, $errors, $index);
					if ($author) {
						$authors[] = array($author, $affiliationId);
					}
				}

				for ($index=0; ($node = $authorGroupNode->getChildByName('Affiliation', $index)) ; $index++) {
					$affiliationId = null;
					$affiliation = MetapressImportDom::handleAffiliationNode($journal, $node, $affiliationId, $errors);
					if ($affiliation && $affiliationId) {
						$affiliations[$affiliationId] = $affiliation;
					}
				}

				$authorDao = DAORegistry::getDAO('AuthorDAO');
				// Assign affiliations to authors.
				foreach ($authors AS $authorArray) {
					$author = $authorArray[0];
					$affiliationId = $authorArray[1];
					if ($affiliationId && array_key_exists($affiliationId, $affiliations)) {
						$author->setAffiliation($affiliation, $journal->getPrimaryLocale()); // Affiliations are not localized in the XML.
					}

					$authorDao->insertAuthor($author);
				}
			}
		}

		// Create submission mangement records
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');

		$initialCopyeditSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_INITIAL', ASSOC_TYPE_ARTICLE, $article->getId());
		$initialCopyeditSignoff->setUserId(0);
		$signoffDao->updateObject($initialCopyeditSignoff);

		$authorCopyeditSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_AUTHOR', ASSOC_TYPE_ARTICLE, $article->getId());
		$authorCopyeditSignoff->setUserId(0);
		$signoffDao->updateObject($authorCopyeditSignoff);

		$finalCopyeditSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_FINAL', ASSOC_TYPE_ARTICLE, $article->getId());
		$finalCopyeditSignoff->setUserId(0);
		$signoffDao->updateObject($finalCopyeditSignoff);

		$layoutSignoff = $signoffDao->build('SIGNOFF_LAYOUT', ASSOC_TYPE_ARTICLE, $article->getId());
		$layoutSignoff->setUserId(0);
		$signoffDao->updateObject($layoutSignoff);

		$authorProofSignoff = $signoffDao->build('SIGNOFF_PROOFREADING_AUTHOR', ASSOC_TYPE_ARTICLE, $article->getId());
		$authorProofSignoff->setUserId(0);
		$signoffDao->updateObject($authorProofSignoff);

		$proofreaderProofSignoff = $signoffDao->build('SIGNOFF_PROOFREADING_PROOFREADER', ASSOC_TYPE_ARTICLE, $article->getId());
		$proofreaderProofSignoff->setUserId(0);
		$signoffDao->updateObject($proofreaderProofSignoff);

		$layoutProofSignoff = $signoffDao->build('SIGNOFF_PROOFREADING_LAYOUT', ASSOC_TYPE_ARTICLE, $article->getId());
		$layoutProofSignoff->setUserId(0);
		$signoffDao->updateObject($layoutProofSignoff);

		// Log the import in the article event log.
		import('classes.article.log.ArticleLog');
		ArticleLog::logEventHeadless(
			$journal, $user->getId(), $article,
			ARTICLE_LOG_ARTICLE_IMPORT,
			'log.imported',
			array('userName' => $user->getFullName(), 'articleId' => $article->getId())
		);

		// Insert published article entry.
		$publishedArticle = new PublishedArticle();
		$publishedArticle->setId($article->getId());
		$publishedArticle->setIssueId($issue->getId());

		if ($publicationDate) {
			$publishedArticle->setDatePublished($publicationDate);
		}
		$publishedArticle->setAccessStatus(ARTICLE_ACCESS_ISSUE_DEFAULT);
		$publishedArticle->setSeq(REALLY_BIG_NUMBER);

		$publishedArticle->setPublishedArticleId($publishedArticleDao->insertPublishedArticle($publishedArticle));

		$publishedArticleDao->resequencePublishedArticles($section->getId(), $issue->getId());

		// Setup default copyright/license metadata after status is set and authors are attached.
		// This handles the case where the XML is not providing it
		$article->initializePermissions();

		// Save permissions data
		$articleDao->updateLocaleFields($article);

		/* --- Galleys (html or otherwise handled simultaneously) --- */
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($article->getId());

		/* --- Handle galleys --- */
		if ($articleInfoNode) {
			$galleyUrlNode = $articleInfoNode->getChildByName('FullTextURL');
			$galleyFileNameNode = $articleInfoNode->getChildByName('FullTextFileName');
			$result = MetapressImportDom::handleGalleyNode($journal, $galleyUrlNode->getValue(), $galleyFileNameNode->getValue(), $submissionFile, $article, $errors, $articleFileManager);
			if (!$result) return false;
		}

		// Index the inserted article.
		import('classes.search.ArticleSearchIndex');
		$articleSearchIndex = new ArticleSearchIndex();
		$articleSearchIndex->articleMetadataChanged($article);
		$articleSearchIndex->articleFilesChanged($article);
		$articleSearchIndex->articleChangesFinished();

		return true;
	}

	/**
	 * Handle an author node (i.e. convert an author from DOM to DAO).
	 * @param $journal Journal
	 * @param $authorNode DOMElement
	 * @param $article Article
	 * @param $affiliationId string
	 * @param $errors array
	 * @param $authorIndex int 0 for first author, 1 for second, ...
	 */
	function handleAuthorNode(&$journal, &$authorNode, &$article, &$affiliationId, &$errors, $authorIndex) {
		$errors = array();

		$journalSupportedLocales = array_keys($journal->getSupportedLocaleNames()); // => journal locales must be set up before
		$author = new Author();
		if (($node = $authorNode->getChildByName('GivenName'))) $author->setFirstName((string)$node->getValue());
		if (($node = $authorNode->getChildByName('Initials'))) $author->setMiddleName($node->getValue());
		if (($node = $authorNode->getChildByName('FamilyName'))) $author->setLastName((string)$node->getValue());
		$affiliationId = $authorNode->getAttribute('AffiliationId');
		$author->setSequence($authorIndex+1); // 1-based
		$author->setSubmissionId($article->getId());
		$author->setEmail('none'); // Email addresses are not in Metapress exports.
		$author->setPrimaryContact($authorIndex == 0 ? 1:0);
		return $author;
	}

	/**
	 * Handle an affiliation node
	 * @param $journal Journal
	 * @param $affiliationNode DOMElement
	 * @param $affiliationId string
	 * @param $errors array
	 */
	function handleAffiliationNode(&$journal, &$affiliationNode, &$affiliationId, &$errors) {
		$errors = array();

		$affiliationId = $affiliationNode->getAttribute('AFFID');
		if (!$affiliationId) return null;

		if (($node = $affiliationNode->getChildByName('OrgName'))) {
			return $node->getValue();
		} else {
			return null;
		}
	}

	/**
	 * Import a remote PDF Galley.
	 * @param Journal $journal
	 * @param string $galleyUrl
	 * @param string $galleyFileName
	 * @param string $submissionFile
	 * @param Article $article
	 * @param array $errors
	 * @param ArticleFileManager $articleFileManager
	 */
	function handleGalleyNode(&$journal, &$galleyUrl, $galleyFileName, &$submissionFile, &$article, &$errors, &$articleFileManager) {
		$errors = array();

		$journalSupportedLocales = array_keys($journal->getSupportedLocaleNames()); // => journal locales must be set up before
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');

		$galley = new ArticleGalley();

		$galley->setArticleId($article->getId());
		$galley->setSequence(1);

		$galley->setLocale($article->getLocale());

		/* --- Galley Label --- */
		$galley->setLabel('PDF');

		// Submission File.
		import('classes.file.TemporaryFileManager');
		import('classes.file.FileManager');

		// Copy the included file if present, otherwise attempt the URL.
		$fileUrl = (isset($submissionFile)) ? $submissionFile : $galleyUrl;

		if (($fileId = $articleFileManager->copyPublicFile($fileUrl, 'application/pdf'))===false) {
			$errors[] = array('plugins.importexport.metapress.import.error.couldNotCopy', array('url' => $url));
			return false;
		}

		if (!isset($fileId)) {
			$errors[] = array('plugins.importexport.metapress.import.error.galleyFileMissing', array('articleTitle' => $article->getLocalizedTitle(), 'sectionTitle' => $section->getLocalizedTitle(), 'issueTitle' => $issue->getIssueIdentification()));
			return false;
		}
		$galley->setFileId($fileId);
		// Update the article file original name with the name from the XML element.
		// handleCopy() uses the basename of the URL by default.
		$articleFileDao = DAORegistry::getDAO('ArticleFileDAO');
		$articleFile = $articleFileDao->getArticleFile($fileId);
		$articleFile->setOriginalFileName($galleyFileName);
		$articleFileDao->updateArticleFile($articleFile);
		$galleyDao->insertGalley($galley);

		return true;

	}


	function cleanupFailure (&$dependentItems) {
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$articleDao =& DAORegistry::getDAO('ArticleDAO');

		foreach ($dependentItems as $dependentItem) {
			$type = array_shift($dependentItem);
			$object = array_shift($dependentItem);

			switch ($type) {
				case 'issue':
					$issueDao->deleteIssue($object);
					break;
				case 'article':
					$articleDao->deleteArticle($object);
					break;
				default:
					fatalError ('cleanupFailure: Unimplemented type');
			}
		}
	}

	/**
	 * Fetch the journal object represented by the JournalCode node.
	 * @param DOMDocument $doc
	 * @param String $journalPath
	 * @return Journal
	 */
	function retrieveJournal(&$doc, &$journalPath) {
		if (($node = $doc->getChildByName('Journal'))) {
			$journalInfoNode = $node->getChildByName('JournalInfo');
			$journalCodeNode = $journalInfoNode->getChildByName('JournalCode');
 			$journalDao = DAORegistry::getDAO('JournalDAO');
 			$journalPath = $journalCodeNode->getValue();
			$journal = $journalDao->getJournalByPath($journalPath);
			return $journal;
		}
	}

	/**
	 * Fetch the issue node represented.
	 * @param DOMDocument $doc
	 * @param String $volumeNumber
	 * @param String $issueNumber
	 * @return DOMNode
	 */
	function getIssueNode(&$doc, &$volumeNumber, &$issueNumber) {
		if (($node = $doc->getChildByName('Journal'))) {
			$volumeNode = $node->getChildByName('Volume');
			$volumeInfoNode = $volumeNode->getChildByName('VolumeInfo');
			$volumeNumberNode = $volumeInfoNode->getChildByName('VolumeNumber');
			$volumeNumber = $volumeNumberNode->getValue();
			$issueNode = $volumeNode->getChildByName('Issue');
			$issueInfoNode = $issueNode->getChildByName('IssueInfo');
			$issueNumberBeginNode = $issueInfoNode->getChildByName('IssueNumberBegin');

			if ($issueNumberBeginNode) {
				$issueNumber = $issueNumberBeginNode->getValue();
			}

			return $issueNode;
		}
	}

	/**
	 * Fetch the article node represented.
	 * @param DOMDocument $doc
	 * @return DOMNode
	 */
	function getArticleNode(&$doc) {
		$issueNode = MetapressImportDom::getIssueNode($doc, $volumeNumber);
		if (($node = $issueNode->getChildByName('Article'))) {
			return $node;
		}
	}
}

?>
