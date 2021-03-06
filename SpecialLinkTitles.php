<?php
/*
 *      Copyright 2012-2014 Daniel Kraus <krada@gmx.net> ('bovender')
 *
 *      This program is free software; you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation; either version 2 of the License, or
 *      (at your option) any later version.
 *
 *      This program is distributed in the hope that it will be useful,
 *      but WITHOUT ANY WARRANTY; without even the implied warranty of
 *      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *      GNU General Public License for more details.
 *
 *      You should have received a copy of the GNU General Public License
 *      along with this program; if not, write to the Free Software
 *      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *      MA 02110-1301, USA.
 */
/// @defgroup batch Batch processing

	/// @cond
  if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'Not an entry point.' );
	}
	/// @endcond
 
/// Provides a special page that can be used to batch-process all pages in 
/// the wiki. By default, this can only be performed by sysops.
/// @ingroup batch
class SpecialLinkTitles extends SpecialPage {

	/// Constructor. Announces the special page title and required user right 
	/// to the parent constructor.
	function __construct() {
		// the second parameter in the following function call ensures that only 
		// users who have the 'linktitles-batch' right get to see this page (by 
		// default, this are all sysop users).
		parent::__construct('LinkTitles', 'linktitles-batch');
	}

	/// Entry function of the special page class. Will abort if the user does 
	/// not have appropriate permissions ('linktitles-batch').
	/// @returns undefined
	function execute($par) {
		// Prevent non-authorized users from executing the batch processing.
		if (  !$this->userCanExecute( $this->getUser() )  ) {
					$this->displayRestrictionError();
							return;
		}

		$request = $this->getRequest();
		$output = $this->getOutput();
		$this->setHeaders();

		// Determine whether this page was requested via GET or POST.
		// If GET, display information and a button to start linking.
		// If POST, start or continue the linking process.
		if ( $request->wasPosted() ) {
			if ( array_key_exists('s', $request->getValues()) ) {
				$this->process($request, $output);
			}
			else
			{
				$this->buildInfoPage($request, $output);
			}
		}
		else
		{
			$this->buildInfoPage($request, $output);
		}
	}

	/// Processes wiki articles, starting at the page indicated by 
	/// $startTitle. If $wgLinkTitlesTimeLimit is reached before all pages are 
	/// processed, returns the title of the next page that needs processing.
	/// @param WebRequest $request WebRequest object that is associated with the special 
	///                            page.
	/// @param OutputPage $output  Output page for the special page.
	private function process( WebRequest &$request, OutputPage &$output) {
		global $wgLinkTitlesTimeLimit;

		// Start the stopwatch
		$startTime = microtime(true);

		// Connect to the database
		$dbr = wfGetDB( DB_SLAVE );

		// Fetch the start index and max number of records from the POST 
		// request.
		$postValues = $request->getValues();

		// Convert the start index to an integer; this helps preventing
		// SQL injection attacks via forged POST requests.
		$start = intval($postValues['s']);

		// If an end index was given, we don't need to query the database
		if ( array_key_exists('e', $postValues) ) {
			$end = intval($postValues['e']);
		}
		else 
		{
			// No end index was given. Therefore, count pages now.
			$end = $this->countPages($dbr);
		};

		array_key_exists('r', $postValues) ?
				$reloads = $postValues['r'] :
				$reloads = 0;

		// Retrieve page names from the database.
		$res = $dbr->select( 
			'page',
			'page_title', 
			array( 
				'page_namespace = 0', 
			), 
			__METHOD__, 
			array(
		 		'LIMIT' => 999999999,
				'OFFSET' => $start
			)
		);

		// Iterate through the pages; break if a time limit is exceeded.
		foreach ( $res as $row ) {
			$curTitle = $row->page_title;
			LinkTitles::processPage($curTitle, $this->getContext());
			$start += 1;
			
			// Check if the time limit is exceeded
			if ( microtime(true)-$startTime > $wgLinkTitlesTimeLimit )
			{
				break;
			}
		}

		$this->addProgressInfo($output, $curTitle, $start, $end);

		// If we have not reached the last page yet, produce code to reload
		// the extension's special page.
		if ( $start < $end )
	 	{
			$reloads += 1;
			// Build a form with hidden values and output JavaScript code that 
			// immediately submits the form in order to continue the process.
			$output->addHTML($this->getReloaderForm($request->getRequestURL(), 
				$start, $end, $reloads));
		}
		else // Last page has been processed
		{
			$this->addCompletedInfo($output, $start, $end, $reloads);
		}
	}

	/// Adds WikiText to the output containing information about the extension 
	/// and a form and button to start linking.
	private function buildInfoPage(&$request, &$output) {
		$url = $request->getRequestURL();

		// TODO: Put the page contents in messages in the i18n file.
		$output->addWikiText(
<<<EOF
LinkTitles extension: http://www.mediawiki.org/wiki/Extension:LinkTitles

Source code: http://github.com/bovender/LinkTitles

== Batch Linking ==
You can start a batch linking process by clicking on the button below.
This will go through every page in the normal namespace of your Wiki and 
insert links automatically. This page will repeatedly reload itself, in 
order to prevent blocking the server. To interrupt the process, simply
close this page.
EOF
		);
		$output->addHTML(
<<<EOF
<form method="post" action="${url}">
	<input type="submit" value="Start linking" />
	<input type="hidden" name="s" value="0" />
</form>
EOF
		);
	}

	/// Produces informative output in WikiText format to show while working.
	/// @param $output    Output object.
	/// @param $curTitle  Title of the currently processed page.
	/// @param $index     Index of the currently processed page.     
	/// @param $end       Last index that will be processed (i.e., number of 
	///                   pages).
	private function addProgressInfo(&$output, $curTitle, $index, $end) {
		$progress = $index / $end * 100;
		$percent = sprintf("%01.1f", $progress);

		$output->addWikiText(
<<<EOF
== Processing pages... ==
The [http://www.mediawiki.org/wiki/Extension:LinkTitles LinkTitles] 
extension is currently going through every page of your wiki, adding links to 
existing pages as appropriate.

=== Current page: $curTitle ===
EOF
		);
		$output->addHTML(
<<<EOF
<p>Page ${index} of ${end}.</p>
<div style="width:100%; padding:2px; border:1px solid #000; position: relative;
		margin-bottom:16px;">
	<span style="position: absolute; left: 50%; font-weight:bold; color:#555;">
		${percent}%
	</span>
	<div style="width:${progress}%; background-color:#bbb; height:20px; margin:0;"></div>
</div>
EOF
		);
		$output->addWikiText(
<<<EOF
=== To abort, close this page, or hit the 'Stop' button in your browser ===
[[Special:LinkTitles|Return to Special:LinkTitles.]]
EOF
		);
	}

	/// Generates an HTML form and JavaScript to automatically submit the 
	/// form.
	/// @param $url     URL to reload with a POST request.
	/// @param $start   Index of the next page that shall be processed.
	/// @param $end     Index of the last page to be processed.
	/// @param $reloads Counter that holds the number of reloads so far.
	/// @returns        String that holds the HTML for a form and a
	///                 JavaScript command.
	private function getReloaderForm($url, $start, $end, $reloads) {
		return
<<<EOF
<form method="post" name="linktitles" action="${url}">
	<input type="hidden" name="s" value="${start}" />
	<input type="hidden" name="e" value="${end}" />
	<input type="hidden" name="r" value="${reloads}" />
</form>
<script type="text/javascript">
	document.linktitles.submit();
</script>
EOF
		;
	}

	/// Adds statistics to the page when all processing is done.
	/// @param $output  Output object
	/// @param $start   Index of the first page that was processed.
	/// @param $end     Index of the last processed page.
	/// @param $reloads Number of reloads of the page.
	/// @returns undefined
	private function addCompletedInfo(&$output, $start, $end, $reloads) {
		global $wgLinkTitlesTimeLimit;
		$pagesPerReload = sprintf('%0.1f', $end / $reloads);
		$output->addWikiText(
<<<EOF
== Batch processing completed! ==
{| class="wikitable"
|-
| total number of pages: || ${end}
|-
| timeout setting [s]: || ${wgLinkTitlesTimeLimit}
|-
| webpage reloads: || ${reloads}
|-
| pages scanned per reload interval: || ${pagesPerReload}
|}
EOF
			);
	}

	/// Counts the number of pages in a read-access wiki database ($dbr).
	/// @param $dbr Read-only `Database` object.
	/// @returns Number of pages in the default namespace (0) of the wiki.
	private function countPages(&$dbr) {
		$res = $dbr->select(
			'page',
			'page_id', 
			array( 
				'page_namespace = 0', 
			), 
			__METHOD__ 
		);
		return $res->numRows();
	}
}

// vim: ts=2:sw=2:noet:comments^=\:///
