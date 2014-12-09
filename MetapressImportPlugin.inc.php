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
				$doc = null;

				while (($mpEntry = readdir($metapressDirHandle)) !== false) {

					$metapressFile = $metapPressDirectoryPath . "/" . $mpEntry;
					if (is_file($metapressFile)) {
						// two possibilities.  An XML file, or a document.
						if (preg_match('/\.xml$/', $mpEntry)) {
							$doc = $this->getDocument($metapressFile);
						} else {
							$submissionFile = $metapressFile;
						}
					}
				}

				if ($doc) {
					$journal = MetapressImportDom::retrieveJournal($doc, $path);
					if ($journal) {
						$issue = MetapressImportDom::importIssue($journal, $doc);
						if ($issue) {
							$result = MetapressImportDom::importArticle($journal, $doc, $issue, $submissionFile, $errors, $user);
							if ($result) {
								echo __('plugins.importexport.metapress.articleImported') . "\n";
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
}
?>
