<?php
if (strpos($_SERVER['REQUEST_URI'], '?') !== false){
	$scripturl =  substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'));
}else{
	$scripturl = $_SERVER['REQUEST_URI'];
}

if ($scripturl != "/membercp.php" && $scripturl != "/forums.php"){
	begin_block(T_("DONATE"));
	echo "<br /><br /><center>This would need to contain your donation code, or something. maybe even a paypal link</center><br /><br />";
	end_block();
}
?>
