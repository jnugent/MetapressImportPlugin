<?php

/**
 * @defgroup plugins_importexport_metapress
 */

/**
 * @file plugins/importexport/metapress/index.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_importexport_metapress
 * @brief Wrapper for metapress XML import plugin.
 *
 */


require_once('MetapressImportPlugin.inc.php');

return new MetapressImportPlugin();

?>
