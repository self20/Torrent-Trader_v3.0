<?php
//
//  TorrentTrader v3.x
//      $LastChangedDate: 2016-10-21 14:55:35 +0100 (Fri, 21 Oct 2016) $
//      $LastChangedBy: Meg4R0M $
//

require_once("backend/functions.php");
dbconn();

stdhead("CHANGELOG");

echo '<table class="forumTable">
    <tr>
	    <th colspan="2" class="forumTableHeader">Changelog</th>
    </tr>
            
    <tr class="forumTableSubHeader">
		<th class="t_icon" style="width: 5%;">Date</th>
		<th class="t_title" style="text-align: left;">Change</th>
    </tr>
            
    <tr class="threadList">
	    <td style="width: 5%; text-align: center;">2016-11-01</td>
	    <td data-th="Title">Comments on Account-details</td>
	</tr>
	<tr class="threadList">
	    <td style="width: 5%; text-align: center;">2016-11-01</td>
	    <td data-th="Title">Anti BruteForce</td>
	</tr>
	        
</table>';
echo '<table class="forumTable">
    <tr>
	    <th colspan="1" class="forumTableHeader">To-do List</th>
    </tr>
            
    <tr class="forumTableSubHeader">
		<th class="t_title" style="text-align: left;">Title</th>
    </tr>
            
    <tr class="threadList">
	    <td data-th="Title">Ajax report</td>
	</tr>
	<tr class="threadList">
	    <td data-th="Title">Like system (Torrents, Users comments and Forums)</td>
	</tr>
	        
</table>';

stdfoot();
?>