<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009-2010 Ingo Renner <ingo.renner@dkd.de>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * General frontend page indexer.
 *
 * @author	Ingo Renner <ingo.renner@dkd.de>
 * @author	Daniel Poetzinger <poetzinger@aoemedia.de>
 * @author	Timo Schmidt <schmidt@aoemedia.de>
 * @package TYPO3
 * @subpackage solr
 */
class tx_solr_Indexer implements tslib_content_PostInitHook {

	protected $page;

	/**
	 * Collects the frontens user group IDs used in content elements on the
	 * page (and the currently logged in user has access to).
	 *
	 * @var	array
	 */
	protected static $contentFrontendUserAccessGroups = array();


	/**
	 * Handles the indexing of the page content during post processing of
	 * a generated page.
	 *
	 * @param	tslib_fe	Typoscript frontend
	 */
	public function hook_indexContent(tslib_fe $page) {
		$this->page = $page;

			//determine if the current page should be indexed
		if ($this->indexingEnabled($this->page)) {
			try {
					// do some checks first
				if ($page->page['no_search']) {
					throw new Exception(
						'Index page? No, The "No Search" flag has been set in the page properties!',
						1234523946
					);
				}

				if ($page->no_cache) {
					throw new Exception(
						'Index page? No, page was set to "no_cache" and so cannot be indexed.',
						1234524030
					);
				}

				if ($page->sys_language_uid != $page->sys_language_content) {
					throw new Exception(
						'Index page? No, ->sys_language_uid was different from sys_language_content which indicates that the page contains fall-back content and that would be falsely indexed as localized content.',
						1234524095
					);
				}

				if ($GLOBALS['TSFE']->beUserLogin && !$GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['enableIndexingWhileBeUserLoggedIn']) {
					throw new Exception(
						'Index page? No, Detected a BE user being logged in.',
						1246444055
					);
				}

					// now index the page
				$this->indexPage($page->id);

			} catch (Exception $e) {
				$this->log($e->getMessage() . ' Error code: ' . $e->getCode(), 3);

				if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['logging.']['exceptions']) {
					t3lib_div::devLog('Exception while trying to index a page', 'tx_solr', 3, array(
						$e->__toString()
					));
				}
			}
		}
	}

	/**
	 * Determines whether indexing is enabled for a given page.
	 *
	 * @param	tslib_fe	Typoscript frontend
	 * @return	boolean	Indicator whether the page should be indexed or not.
	 */
	protected function indexingEnabled(tslib_fe $page) {
		$indexingEnabled = false;

		if ($page->config['config']['index_enable']) {
			if (is_array($page->applicationData['tx_crawler'])) {
				$crawlerActive = t3lib_extMgm::isLoaded('crawler') && $page->applicationData['tx_crawler']['running'];
			}

			$solrPageIndexingEnabled    = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['enablePageIndexing'];
			$solrCrawlerIndexingEnabled = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['enableCrawlerIndexing'];

			/*
			 * A page can be indexed by a normal frontend call or by the crawler.
			 * In case of the crawler the following requirements need to fit:
			 *   - Indexing of page in generall enabled
			 *   - Indexing with the crawler needs to be enabled in the settings
			 *   - Crawler needs to be loaded
			 *   - Crawler needs to be active
			 *   - Processing instruction needs to be active
			 * In case of an normal frontend indexing call the following requirements need to fit:
			 *   - Indexing of page in generall enabled
			 *   - The pageIndexing needs to be enabled in the solr config
			 */
			if ($solrCrawlerIndexingEnabled && $crawlerActive) {
				$solrProcessingInstructionActive = in_array(
					'tx_solr_reindex',
					$page->applicationData['tx_crawler']['parameters']['procInstructions']
				);
				$page->applicationData['tx_crawler']['log'][] = 'Solr is indexing';

				if ($solrProcessingInstructionActive) {
					$indexingEnabled = true;
				}
			} elseif ($solrPageIndexingEnabled) {
				$indexingEnabled = true;
			}
		}

		return $indexingEnabled;
	}

	/**
	 * Indexes a page.
	 *
	 * @param	integer	page uid
	 * @return	boolean	true after successfully indexing the page, false on error
	 * @todo	transform this into a more generic "indexRecord()" function later
	 */
	protected function indexPage($pageId) {
		$documents = array(); // this will become usefull as soon as when starting to index individual records instead of whole pages

		try {
				// get a solr connection
			$solr = t3lib_div::makeInstance('tx_solr_ConnectionManager')->getConnectionByPageId(
				$pageId,
				$GLOBALS['TSFE']->sys_language_uid
			);

				// do not continue if no server is available
			if (!$solr->ping()) {
				throw new Exception(
					'No Solr instance available during indexing.',
					1234790825
				);
			}
		} catch (Exception $e) {
			$this->log($e->getMessage() . ' Error code: ' . $e->getCode(), 3);

			if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['logging.']['exceptions']) {
				t3lib_div::devLog('exception while trying to index a page', 'tx_solr', 3, array(
					$e->__toString()
				));
			}

				// intended early return as it doesn't make sense to continue
				// and waste processing time if the solr server isn't available
				// anyways
			return false;
		}

		$pageDocument = $this->pageToDocument($pageId);

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageSubstitutePageDocument'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageSubstitutePageDocument'] as $classReference) {
				$substituteIndexer = &t3lib_div::getUserObj($classReference);

				if ($substituteIndexer instanceof tx_solr_SubstitutePageIndexer) {
					$substituteDocument = $substituteIndexer->getPageDocument($pageDocument);

					if ($substituteDocument instanceof Apache_Solr_Document) {
						$pageDocument = $substituteDocument;
					} else {
						// TODO throw an exception
					}
				} else {
					// TODO throw an exception
				}
			}
		}
		$documents[] = $pageDocument;

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageAddDocuments'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageAddDocuments'] as $classReference) {
				$additionalIndexer = &t3lib_div::getUserObj($classReference);

				if ($additionalIndexer instanceof tx_solr_AdditionalIndexer) {
					$additionalDocuments = $additionalIndexer->getAdditionalDocuments($pageDocument, $documents);

					if (is_array($additionalDocuments)) {
						$documents = array_merge($documents, $additionalDocuments);
					} else {
						// TODO throw an exception
					}
				} else {
					// TODO throw an exception
				}
			}
		}

		$documents = $this->addTypoScriptConfiguredFieldsToDocuments($documents);
		$this->processDocuments($documents);

		if (count($documents)) {
			try {
				$this->log('Adding ' . count($documents) . ' documents.', 0, $documents);

					// chunk adds by 20
				$chunkedDocuments = array_chunk($documents, 20);
				foreach ($chunkedDocuments as $documentsToAdd) {
					$solr->addDocuments($documentsToAdd);
				}

				return true;
			} catch (Exception $e) {
				$this->log($e->getMessage() . ' Error code: ' . $e->getCode(), 2);

				if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['logging.']['exceptions']) {
					t3lib_div::devLog('Exception while adding documents', 'tx_solr', 3, array(
						$e->__toString()
					));
				}
			}
		}

		return false;
	}

	/**
	 * Sends the given documents to the field processing service which takes
	 * care of manipulating fields as defined in the field's configuration.
	 *
	 * @param	array	An array of documents to manipulate
	 */
	protected function processDocuments(array $documents) {
		if (is_array($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['fieldProcessingInstructions.'])) {
			$service = t3lib_div::makeInstance('tx_solr_fieldprocessor_Service');
			$service->processDocuments(
				$documents,
				$GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['fieldProcessingInstructions.']
			);
		}
	}

	/**
	 * Given a page id, returns a document representing that page.
	 *
	 * @param	integer	Page id
	 * @return	Apache_Solr_Document	A documment representing the page
	 */
	protected function pageToDocument($pageId) {
		$page     = $GLOBALS['TSFE'];
		$document = null;

		$contentExtractor = t3lib_div::makeInstance(
			'tx_solr_Typo3PageContentExtractor',
			$GLOBALS['TSFE']->content,
			$GLOBALS['TSFE']->renderCharset
		);

		$document = t3lib_div::makeInstance('Apache_Solr_Document');
		$cHash    = $this->filterInvalidContentHash($page->cHash);

		$accessGroups = $this->getAccessGroups();

		$document->addField('id', tx_solr_Util::getPageDocumentId(
			$page->id,
			$page->type,
			$page->sys_language_uid,
			implode(',', $accessGroups),
			$cHash
		));
		$document->addField('site',        t3lib_div::getIndpEnv('TYPO3_SITE_URL'));
		$document->addField('siteHash',    tx_solr_Util::getSiteHash());
		$document->addField('appKey',      'EXT:solr'); // TODO add a more meaningful app key
		$document->addField('type',        'pages');
		$document->addField('contentHash', $cHash);

			// system fields
		$document->addField('uid',      $page->id);
		$document->addField('pid',      $page->page['pid']);
		$document->addField('typeNum',  $page->type);
		$document->addField('created',  $page->page['crdate']);
		$document->addField('changed',  $page->page['tstamp']);
		$document->addField('language', $page->sys_language_uid);

			// access
		$document->addField('access', 'c:' . implode(',', $accessGroups));

		if ($page->page['endtime']) {
			$document->addField('endtime', $page->page['endtime']);
		}

			// content
		$title = $contentExtractor->getPageTitle();
		$document->addField('title',       $GLOBALS['TSFE']->csConvObj->utf8_encode($title, $GLOBALS['TSFE']->renderCharset));
		$document->addField('subTitle',    $GLOBALS['TSFE']->csConvObj->utf8_encode($page->page['subtitle'], $GLOBALS['TSFE']->renderCharset));
		$document->addField('navTitle',    $GLOBALS['TSFE']->csConvObj->utf8_encode($page->page['nav_title'], $GLOBALS['TSFE']->renderCharset));
		$document->addField('author',      $GLOBALS['TSFE']->csConvObj->utf8_encode($page->page['author'], $GLOBALS['TSFE']->renderCharset));
		$keywords = array_unique(t3lib_div::trimExplode(',', $GLOBALS['TSFE']->csConvObj->utf8_encode($page->page['keywords'], $GLOBALS['TSFE']->renderCharset)));
		foreach ($keywords as $keyword) {
			$document->addField('keywords', $keyword);
		}
		$document->addField('description', trim($GLOBALS['TSFE']->csConvObj->utf8_encode($page->page['description'], $GLOBALS['TSFE']->renderCharset)));
		$document->addField('abstract',    trim($GLOBALS['TSFE']->csConvObj->utf8_encode($page->page['abstract'], $GLOBALS['TSFE']->renderCharset)));

		$document->addField('content', $contentExtractor->getIndexableContent());

		$document->addField('url', t3lib_div::getIndpEnv('TYPO3_REQUEST_URL'));

		$indexableMarkup = $contentExtractor->getContentMarkedForIndexing();
		$document = self::addTagsToDocument($document, $indexableMarkup);

			// TODO add a hook to allow post processing of the document

		return $document;
	}

	/**
	 * Extracts HTML tag content from the content and adds it to the document to boost fields.
	 *
	 * @param	Apache_Solr_Document	the document
	 * @param	string	content to parse for special HTML tags
	 * @return	Apache_Solr_Document	the document with tags added
	 */
	static public function addTagsToDocument(Apache_Solr_Document $document, $content) {

		$tagMapping = array(
			'h1'     => 'tagsH1',
			'h2'     => 'tagsH2H3',
			'h3'     => 'tagsH2H3',
			'h4'     => 'tagsH4H5H6',
			'h5'     => 'tagsH4H5H6',
			'h6'     => 'tagsH4H5H6',
			'u'      => 'tagsInline',
			'b'      => 'tagsInline',
			'strong' => 'tagsInline',
			'i'      => 'tagsInline',
			'em'     => 'tagsInline',
			'a'      => 'tagsA',
		);

			// strip all ignored tags
		$content = strip_tags($content, '<' . implode('><', array_keys($tagMapping)) . '>');

		preg_match_all('@<('. implode('|', array_keys($tagMapping)) .')[^>]*>(.*)</\1>@Ui', $content, $matches);
		foreach ($matches[1] as $key => $tag) {
				// We don't want to index links auto-generated by the url filter.
			if ($tag != 'a' || !preg_match('@(?:http://|https://|ftp://|mailto:|smb://|afp://|file://|gopher://|news://|ssl://|sslv2://|sslv3://|tls://|tcp://|udp://|www\.)[a-zA-Z0-9]+@', $matches[2][$key])) {
				$document->{$tagMapping[$tag]} .= ' '. $matches[2][$key];
			}
		}

		return $document;
	}

	/**
	 * Adds additional fields to the document, that have been defined through
	 * TypoScript.
	 *
	 * @param	array	an array of Apache_Solr_Document objects
	 * @return 	array	an array of Apache_Solr_Document objects with additional fields
	 */
	protected function addTypoScriptConfiguredFieldsToDocuments(array $documents) {
		$additionalFields = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['additionalFields.'];

		if (is_array($additionalFields)) {
			foreach ($documents as $document) {
				foreach ($additionalFields as $fieldName => $fieldValue) {
						// if its just the configuration array skip this field
					if (is_array($fieldValue)) {
						continue;
					}
						// support for cObject if the value is a configuration
					if (is_array($additionalFields[$fieldName . '.'])) {
						$fieldValue = $GLOBALS['TSFE']->cObj->cObjGetSingle(
							$fieldValue,
							$additionalFields[$fieldName . '.']
						);
					}

					if (substr($fieldName, -8) == '_stringS'
						|| substr($fieldName, -6) == '_textS'
						|| substr($fieldName, -7) == '_textTS'
						|| substr($fieldName, -10) == '_textSortS'
						|| substr($fieldName, -9) == '_textWstS') {
							// utf8 encode string and text fields
						$document->addField(
							$fieldName,
							$GLOBALS['TSFE']->csConvObj->utf8_encode(
								$fieldValue,
								$GLOBALS['TSFE']->renderCharset
							)
						);
					} else {
						$document->addField($fieldName, $fieldValue);
					}
				}
			}
		}

		return $documents;
	}


	// retrieving content


	/**
	 * Checks whether a given string is a valid cHash.
	 * If the hash is valid it will be returned as is, an empty string will be
	 * returned otherwise.
	 *
	 * @param	string	The cHash to check for validity
	 * @return	string	The passed cHash if valid, an empty string if invalid
	 * @see tslib_fe->makeCacheHash
	 */
	protected function filterInvalidContentHash($cHash) {
		$urlParameters   = t3lib_div::_GET();
		$cHashParameters = t3lib_div::cHashParams(t3lib_div::implodeArrayForUrl('', $urlParameters));

		$calculatedCHash = t3lib_div::calculateCHash($cHashParameters);

		return ($calculatedCHash == $cHash) ? $cHash : '';
	}


	// Access Restrictions


	/**
	 * Gets the groups that have access to this page.
	 *
	 * @return	array	An array of FE user group IDs.
	 */
	protected function getAccessGroups() {
		$groups = array();

		$groups = array_merge(
			$groups,
			$this->getAccessGroupsFromContent(),
			$this->getAccessGroupsFromCurrentPage(),
			$this->getAccessGroupsFromParentPages()
		);

		/*
		If any access restrictions have been set for a page or content element,
		we must remove public access (virtual group 0) from the whole document.
		*/
		$accessRestrictingGroups = array_diff($groups, array(0));
		if (count($accessRestrictingGroups)) {
			$groups = array_filter($groups); // removes group 0 / public access
		}

		/*
		We need to remove groups which are not in TSFE->gr_list, otherwise
		access might be restricted more than it needs to be:

		If a page is set to be restricted for multiple groups (1,2) this means
		that a user with either group 1 OR group 2 can see this page. Until now
		we have collected ALL the groups that have been set, which would work
		like an AND. If a user which is member of only one group hits such a
		page, this would result in a document requiring more access groups than
		necessary: 1 AND 2 instead of 1 OR 2. Thus we filter out groups that the
		current user is not a member of. As these are not in TSEF->gr_list, we
		can simply use the gr_list as a filter.
		*/
		$grList = t3lib_div::intExplode(',', $GLOBALS['TSFE']->gr_list);
		$groups = array_intersect($grList, $groups);

		return $this->cleanGroupArray($groups);
	}

	/**
	 * Hook for post processing the initialization of tslib_cObj
	 *
	 * @param	tslib_cObj	parent content object
	 */
	public function postProcessContentObjectInitialization(tslib_cObj &$parentObject) {
		if (!empty($parentObject->currentRecord)) {
			list($table) = explode(':', $parentObject->currentRecord);

			if (!empty($table)
				&& $table != 'pages'
				&& $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['fe_group']
			) {
				self::$contentFrontendUserAccessGroups[] = $parentObject->data[$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['fe_group']];
			}
		}
	}

	/**
	 * Gets the groups set as access restrictions on content elements present
	 * on the current page.
	 *
	 * @return	array	An array of fe group IDs.
	 */
	protected function getAccessGroupsFromContent() {
		$groupList = implode(',', self::$contentFrontendUserAccessGroups);
		$groups    = t3lib_div::intExplode(',', $groupList);

		return $this->cleanGroupArray($groups);
	}


	/**
	 * Gets groups set for the current page.
	 *
	 * @return	array	An array of fe group IDs.
	 */
	protected function getAccessGroupsFromCurrentPage() {
		$groups = t3lib_div::intExplode(
			',',
			$GLOBALS['TSFE']->page['fe_group']
		);

		return $this->cleanGroupArray($groups);
	}

	/**
	 * Gets groups which have been inherited from pages up in the rootline
	 * through the extendToSubpages flag.
	 *
	 * @return	array	An array of fe group IDs.
	 */
	protected function getAccessGroupsFromParentPages() {
		$groups = array();

		$rootLine = $GLOBALS['TSFE']->sys_page->getRootLine($GLOBALS['TSFE']->id);
		foreach ($rootLine as $pageRecord) {
			if ($pageRecord['fe_group']
			&& $pageRecord['extendToSubpages']
			&& $pageRecord['uid'] != $GLOBALS['TSFE']->id
			) {
				$groupsOnPageUpInRootline = t3lib_div::intExplode(
					',', $pageRecord['fe_group']
				);

				$groups = array_merge(
					$groups,
					$groupsOnPageUpInRootline
				);
			}
		}

		return $this->cleanGroupArray($groups);
	}

	/**
	 * Cleans an array of frontend user group IDs. Removes duplicates, sorts,
	 * and reindexes the array.
	 *
	 * @param	array	An array of fe group IDs
	 * @return	array	An array of cleaned fe group IDs, unique, no 0, sorted, indexed.
	 */
	protected function cleanGroupArray(array $groups) {
		$groups = array_unique($groups); // removes duplicates
		sort($groups, SORT_NUMERIC);     // sort
		$groups = array_values($groups); // rebuilds the numerical index

		return $groups;
	}


	// Logging


	/**
	 * Logs messages to devlog and TS log (admin panel)
	 *
	 * @param	string		Message to set
	 * @param	integer		Error number
	 * @return	void
	 */
	protected function log($message, $errorNum = 0, array $data = array()) {
		if (is_object($GLOBALS['TT'])) {
			$GLOBALS['TT']->setTSlogMessage('tx_solr: ' . $message, $errorNum);
		}

		if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['logging.']['indexing']) {
			if (!empty($data)) {
				$logData = array();
				foreach ($data as $value) {
					$logData[] = (array) $value;
				}
			}

			t3lib_div::devLog($message, 'tx_solr', $errorNum, $logData);
		}
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/solr/classes/class.tx_solr_indexer.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/solr/classes/class.tx_solr_indexer.php']);
}

?>