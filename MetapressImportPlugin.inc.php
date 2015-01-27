<?php

/**
 * @file plugins/importexport/metapress/MetapressImportPlugin.inc.php
 *
 * Copyright (c) 2013-2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MetapressImportPlugin
 * @ingroup plugins_importexport_metapress
 *
 * @brief Metapress XML import plugin
 */

import('classes.plugins.ImportExportPlugin');


class MetapressImportPlugin extends ImportExportPlugin {
	/**
	 * Constructor
	 */
	function MetapressImportPlugin() {
		parent::ImportExportPlugin();
	}

	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'MetapressImportPlugin';
	}

	function getDisplayName() {
		return __('plugins.importexport.metapress.displayName');
	}

	function getDescription() {
		return __('plugins.importexport.metapress.description');
	}

	/**
	 * Execute import tasks using the command-line interface.
	 * @param $args Parameters to the plugin
	 */
	function executeCLI($scriptName, &$args) {

		$directory = array_shift($args);
		$username = array_shift($args);

		if (!$directory || !$username) {
			$this->usage($scriptName);
			exit();
		}

		if (!file_exists($directory) && is_dir($directory) ) {
			echo __('plugins.importexport.metapress.directoryDoesNotExist', array('directory' => $directory)) . "\n";
			exit();
		}

		$userDao = DAORegistry::getDAO('UserDAO');
			$user = $userDao->getByUsername($username);
			if (!$user) {
				echo __('plugins.importexport.metapress.unknownUser', array('username' => $username)) . "\n";
				exit();
			}

		$handle  = opendir($directory);
		$this->import('MetapressImportDom');

		while (($entry = readdir($handle)) !== false) {
			$metapPressDirectoryPath = $directory . "/" . $entry;
			if (is_dir($metapPressDirectoryPath) && !preg_match('/^\./', $entry)) { // is a directory, but not a hidden one or . or ..
				$metapressDirHandle = opendir($metapPressDirectoryPath);
				$submissionFile = null;
				$htmlFile = null;
				$doc = null;

				while (($mpEntry = readdir($metapressDirHandle)) !== false) {

					$metapressFile = $metapPressDirectoryPath . "/" . $mpEntry;
					if (is_file($metapressFile)) {
						// three possibilities.  An XML file, or a document that can be either PDF or HTML.
						// we exclude testing for the mediaobjects directory at this point
						if (preg_match('/\.xml$/', $mpEntry)) {
							$doc = $this->getDocument($metapressFile);
						} else {
							if (preg_match('/\.pdf$/', $mpEntry)) {
								$submissionFile = $metapressFile;
							} else {
								$htmlFile = $metapressFile;
							}
						}
					}
				}

				if ($doc) {
					$journal = MetapressImportDom::retrieveJournal($doc, $path);
					if ($journal) {
						$issue = MetapressImportDom::importIssue($journal, $doc);
						if ($issue) {
							$article = MetapressImportDom::importArticle($journal, $doc, $issue, $submissionFile, $errors, $user);
							if ($article) {
								// we have successfully dealt with the XML and PDF.  If there is an HTML
								// galley, do that now.
								if ($htmlFile != null) {
									$articleHTMLGalley = $this->importHTMLGalley($htmlFile, $article, $journal, $metapPressDirectoryPath);
								}
								if ($article) {
									echo __('plugins.importexport.metapress.articleImported') . "\n";
								}
							}
						}
					} else {
						echo __('plugins.importexport.metapress.unknownJournal', array('journalPath' => $path)) . "\n";
					}
				} else {
					echo __('plugins.importexport.metapress.unableToParseDocument', array('directory' => $metapPressDirectoryPath)) . "\n";
				}
			}

			unset($metapPressDirectoryPath);
		}

		exit();
	}

	/**
	 * Display the command-line usage information
	 */
	function usage($scriptName) {
		echo __('plugins.importexport.metapress.cliUsage', array(
			'scriptName' => $scriptName,
			'pluginName' => $this->getName()
		)) . "\n";
	}

	function &getDocument($fileName) {
		$parser = new XMLParser();
		$returner =& $parser->parse($fileName);
		return $returner;
	}

	/**
	 * Import an HTML galley and assoiated Media Objects (images).
	 * @param string $htmlFile the full path to the HTML galley file
	 * @param Article $article
	 * @param Journal $journal
	 * @param string $metapressDirectoryPath the base directory for this Metapress object.
	 * @return ArticleHTMLgalley
	 */
	function importHTMLGalley($htmlFile, $article, $journal, $metapressDirectoryPath) {
		$journalSupportedLocales = array_keys($journal->getSupportedLocaleNames()); // => journal locales must be set up before
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');

		$galley = new ArticleHTMLGalley();

		$galley->setArticleId($article->getId());
		$galley->setSequence(2);  // Assume we have already imported a PDF at this point.

		$galley->setLocale($article->getLocale());

		/* --- Galley Label --- */
		$galley->setLabel('HTML');

		// Submission File.
		import('classes.file.TemporaryFileManager');
		import('classes.file.FileManager');

		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($article->getId());

		if (($fileId = $articleFileManager->copyPublicFile($htmlFile, 'text/html'))===false) {
			$errors[] = array('plugins.importexport.metapress.import.error.couldNotCopy', array('url' => $htmlFile));
			return false;
		}

		if (!isset($fileId)) {
			$errors[] = array('plugins.importexport.metapress.import.error.galleyFileMissing', array('articleTitle' => $article->getLocalizedTitle(), 'sectionTitle' => $section->getLocalizedTitle(), 'issueTitle' => $issue->getIssueIdentification()));
			return false;
		}
		$galley->setFileId($fileId);
		// Update the article file original name with the name from the HTML element.
		// handleCopy() uses the basename of the URL by default.

		$articleFileDao = DAORegistry::getDAO('ArticleFileDAO');
		$articleFile = $articleFileDao->getArticleFile($fileId);
		$galleyFileName = $htmlFile;
		if (preg_match('|/([^/]+)$|', $htmlFile, $matches)) {
			$galleyFileName = $matches[1];
		}
		$articleFile->setOriginalFileName($galleyFileName);
		$articleFileDao->updateArticleFile($articleFile);
		$galleyId = $galleyDao->insertGalley($galley);

		// Galley created.  Check for mediaobjects directory.
		$mediaObjectsDir = $metapressDirectoryPath . '/mediaobjects';
		if (is_dir($mediaObjectsDir)) {

			$handle = opendir($mediaObjectsDir);
			while (($entry = readdir($handle)) !== false) {
				$mediaFile = $mediaObjectsDir . '/' . $entry;
				if (is_file($mediaFile) && !preg_match('/^\./', $entry)) { // it' a file, not . or ..
					if ($fileId = $articleFileManager->copyPublicFile($mediaFile, $articleFileManager->getUploadedFileType($mediaFile))) {

						// fix the file name and mime type since this was copied and we don't
						// really know the mime.
						$mediaObjectFile = $articleFileDao->getArticleFile($fileId);
						$fileExtension = $articleFileManager->parseFileExtension($mediaObjectFile->getOriginalFileName());
						switch ($fileExtension) {
							case 'jpg':
								$mediaObjectFile->setFileType('image/jpeg');
								break;
							default:
								$mediaObjectFile->setFileType('image/' . str_replace('.', '', $fileExtension));
						}

						$mediaObjectFile->setOriginalFileName('mediaobjects/' . $mediaObjectFile->getOriginalFileName());
						$articleFileDao->updateArticleFile($mediaObjectFile);

						$galleyDao->insertGalleyImage($galleyId, $fileId);
						$galley->setImageFiles($galleyDao->getGalleyImages($galleyId));
						$galleyDao->updateGalley($galley);
					}
				}
			}
		}
		return $galley;
	}
}
?>
