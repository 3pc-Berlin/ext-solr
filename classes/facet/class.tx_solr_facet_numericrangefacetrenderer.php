<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011-2012 Michiel Roos <michiel@maxserv.nl>
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
 * Numeric range facet renderer.
 *
 * @author Michiel Roos <michiel@maxserv.nl>
 * @author Ingo Renner <ingo@typo3.org>
 * @package TYPO3
 * @subpackage solr
 */
class tx_solr_facet_NumericRangeFacetRenderer extends tx_solr_facet_AbstractFacetRenderer {

	/**
	 * Renders a numeric range facet by providing a slider
	 *
	 * @see tx_solr_facet_AbstractFacetRenderer::renderFacet()
	 */
	protected function renderFacetOptions() {
		$this->loadJavaScriptFiles();
		$handlePositions = $this->getHandlePositions();

			// the option's value will be appended by javascript after the slide event
		$incompleteFacetOption = t3lib_div::makeInstance('tx_solr_facet_FacetOption',
			$this->search->getQuery(),
			$this->facetName,
			''
		);

		$content = $this->getRangeSliderJavaScript($handlePositions['start'], $handlePositions['end']);
		$content .= '
			<input type="hidden" id="facet-' . $this->facetName . '-url" value="' . $incompleteFacetOption->getReplaceFacetOptionUrl() . '">
			<div id="facet-' . $this->facetName . '-value" >' . $handlePositions['start'] . ' - ' . $handlePositions['end'] . '</div>
			<div id="facet-' . $this->facetName . '-range"></div>
		';

		return $content;
	}

	/**
	 * Gets the handle positions for the slider.
	 *
	 * @return array Array with keys start and end
	 */
	protected function getHandlePositions() {
			// default to maximum range: start - end
		$facetOptions    = $this->getFacetOptions();
		$handle1Position = $facetOptions['start'];
		$handle2Position = $facetOptions['end'];

			// TODO implement $query->getFacetFilter($facetName), provide facet name, get filters for facet
		$filters = $this->search->getQuery()->getFilters();
		foreach ($filters as $filter) {
			if (preg_match("/\(" . $this->facetConfiguration['field'] . ":\[(.*)\]\)/", $filter, $matches) ){
				$range = explode('TO', $matches[1]);
				$range = array_map('trim', $range);

				$handle1Position = $range[0];
				$handle2Position = $range[1];
				break;
			}
		}

		return array('start' => $handle1Position, 'end' => $handle2Position);
	}

	/**
	 * Adds the javascript required to activate the range sliders on the page.
	 *
	 * @param integer $handle1Position Position of the left handle
	 * @param integer $handle2Position Position of the right handle
	 */
	protected function getRangeSliderJavaScript($handle1Position, $handle2Position) {
		$facetOptions = $this->getFacetOptions();

		$rangeSliderJavaScript = '
			<script type="text/javascript">
				jQuery(document).ready(function(){
					jQuery("#facet-' . $this->facetName . '-range").slider({
						range: true,
						values: [' . $handle1Position . ',' . $handle2Position . '],
						min: '  . $facetOptions['start'] . ',
						max: '  . $facetOptions['end'] . ',
						step: ' . $facetOptions['gap'] . ',
						slide: function( event, ui ) {
							jQuery( "#facet-' . $this->facetName . '-value" ).html( "" + ui.values[0] + " - " + ui.values[1] );
							solrRangeRequest("' . $this->facetName . '", "-");
						}
					});
					jQuery( "#facet-' . $this->facetName . '-value" ).val( "" + jQuery( "#facet-' . $this->facetName . '-range" ).slider( "values", 0 ) +
						" - " + jQuery( "#facet-' . $this->facetName . '-range" ).slider( "values", 1 ) );
				});
			</script>
		';

		return $rangeSliderJavaScript;
	}

	/**
	 * Loads javascript libraries for the sliders.
	 *
	 */
	protected function loadJavaScriptFiles() {
		$javascriptManager = t3lib_div::makeInstance('tx_solr_JavascriptManager');

		$javascriptManager->loadFile('library');
		$javascriptManager->loadFile('ui');
		$javascriptManager->loadFile('ui.slider');

		$javascriptManager->loadFile('faceting.numericRangeHelper');

		$javascriptManager->addJavascriptToPageHeader();
	}

	/**
	 * (non-PHPdoc)
	 * @see tx_solr_facet_AbstractFacetRenderer::getFacetOptions()
	 */
	public function getFacetOptions() {
		$facetField   = $this->facetConfiguration['field'];
		$facetOptions = $this->search->getRangeFacetFieldOptions($facetField);

		return $facetOptions;
	}

	/**
	 * (non-PHPdoc)
	 * @see tx_solr_facet_AbstractFacetRenderer::getFacetOptionsCount()
	 */
	public function getFacetOptionsCount() {
		$facetField  = $this->facetConfiguration['field'];
		$facetCounts = $this->search->getFacetCounts();

		return count((array) $facetCounts->facet_ranges->$facetField->counts);
	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/solr/classes/facet/class.tx_solr_facet_numericrangefacetrenderer.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/solr/classes/facet/class.tx_solr_facet_numericrangefacetrenderer.php']);
}

?>
