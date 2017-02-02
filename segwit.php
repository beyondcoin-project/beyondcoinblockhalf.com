<?php
include_once("analyticstracking.php");
require_once 'jsonRPCClient.php';

define("BIP9_TOPBITS_VERSION", 0x20000000);
define("CSV_SEGWIT_BLOCK_VERSION", 0x20000003);
define("SEGWIT_BLOCK_VERSION", 0x20000002);
define("BLOCK_TARGET",  8064);
define("SEGWIT_SIGNAL_START", 1145088);
define("BLOCK_RETARGET_INTERVAL", 2016);

$litecoin = new jsonRPCClient('http://user:pass@127.0.0.1:9332/');
$blockCount = $litecoin->getblockcount();
$blockChainInfo = $litecoin->getblockchaininfo(); 

$activeSFs = GetSoftforks($blockChainInfo["softforks"], 1); 
$activeBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], "active");
$activeSFs = array_merge($activeSFs, $activeBIP9SFs);

$pendingSFs = GetSoftforks($blockChainInfo["softforks"], 0); 
$pendingBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], "defined|started");
$pendingSFs = array_merge($pendingSFs, $pendingBIP9SFs);

$segwitInfo = $blockChainInfo["bip9_softforks"]["segwit"]; 
$segwitActive = ($segwitInfo["status"] == "active") ? true : false; 

$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211) or die("Unable to connect to Memcached.");

$blockHeight = $mem->get("blockheight");
$versions = $mem->get("versions");
$blocksPerDay = (60 / 2.5) * 24;
$nextRetargetBlock = GetNextRetarget($blockCount) * BLOCK_RETARGET_INTERVAL;
$blockETA = ($nextRetargetBlock - $blockCount) / $blocksPerDay * 24 * 60 * 60;

if (!$versions)
	$versions = array(BIP9_TOPBITS_VERSION, CSV_SEGWIT_BLOCK_VERSION, SEGWIT_BLOCK_VERSION);

$verbose = false;

if ($blockHeight) {
	if ($blockHeight != $blockCount) {
		$diff = $blockCount - $blockHeight;
		if ($verbose)
			echo 'Diff is: ' . $diff . '<br>';

		for ($i = $blockCount; $i > $blockHeight; $i--) {
			$blockVer = GetBlockVersion($i, $litecoin);
			if ($verbose)
				echo 'New block. Height: ' . $i . ' with block version ' . $blockVer . '.<br>';
			$result = $mem->get($blockVer);
			$mem->set($blockVer, $result+1);	
		}
		
		for ($i = $blockCount - BLOCK_TARGET; $i < ($blockCount - BLOCK_TARGET) + $diff; $i++) {
			$blockVer = GetBlockVersion($i, $litecoin);
			if ($verbose)
				echo 'Removing block. Height: ' . $i . ' with block version ' . $blockVer . '.<br>';
			$result = $mem->get($blockVer);
			$mem->set($blockVer, $result-1);	
		}
		$mem->set("blockheight", $blockCount);
	} 
} else {
	$mem->set('blockheight', $blockCount);
	for ($i = $blockCount - BLOCK_TARGET + 1; $i <= $blockCount; $i++) {
		$blockVer = GetBlockVersion($i, $litecoin);
		
		if (!in_array($blockVer, $versions)) {
			array_push($versions, $blockVer);
		}

		$result = $mem->get($blockVer);
		if (!$result) {
			if ($verbose)
				echo 'Creating new blockver: ' . $blockVer . '<br>';
			$mem->set($blockVer, 1);
		} else {
			if ($verbose)
				echo 'Setting block verion: ' . $blockVer . ' count value to: ' . $result . '<br>';
			$mem->set($blockVer, $result+1);
		}
	}
	$mem->set('versions', $versions);
}

if ($verbose)
	GetBlockRangeSummary($versions, $mem);

$segwitBlocks = GetBlockVersionCounter(CSV_SEGWIT_BLOCK_VERSION, $mem) + GetBlockVersionCounter(SEGWIT_BLOCK_VERSION, $mem);
$bip9Blocks = GetBlockVersionCounter(BIP9_TOPBITS_VERSION, $mem);

$segwitSignalling = ($blockcount >= SEGWIT_SIGNAL_START) ? true : false;
$displayText = "The Segregated Witness (segwit) soft fork will start signalling on block number " . $nextRetargetBlock . ".";
if ($segwitSignalling) {
	$displayText = "The Segregated Witness (segwit) soft fork has started signalling! <br/><br/> Ask your pool to support segwit if it isn't already doing so.";
}

function GetNextRetargetETA($time) {
	$timeNew = strtotime('+' . $time . ' second', time());
	$now = new DateTime();
	$futureDate = new DateTime();
	$futureDate = DateTime::createFromFormat('U', $timeNew);
	$interval = $futureDate->diff($now);
	return $interval->format("%a days, %h hours, %i minutes");
}

function GetNextRetarget($block) {
	$iterations = 0;
	for ($i = 0; $i < $block; $i += 2016) {
		$iterations++;
	}
	if (($iterations * BLOCK_RETARGET_INTERVAL) == $block) {
		$iterations++;
	}
	return $iterations;
}

function GetBlockRangeSummary($versions, $memcache) {
	echo 'Current block height: ' . $memcache->get('blockheight') . '<br>';
	$totalBlocks = 0;
	foreach ($versions as $version) {
		$counter = GetBlockVersionCounter($version, $memcache);
		if ($counter == 0) {
			continue;
		}
		echo $counter . ' version ' . dechex($version) . ' blocks. <br>';
		$totalBlocks += $counter;
	}
	echo $totalBlocks . ' total blocks. <br>';
}

function GetBlockVersion($blockNum, $rpc) {
	$blockhash = $rpc->getblockhash($blockNum);
	$block = $rpc->getblock($blockhash);
	return $block['version'];
}

function GetBIP9Softforks($softforks, $active) {
	$result = array();

	while ($softfork = current($softforks)) {
		$key = key($softforks);
		if (strpos($active, '|') !== false) {
			$status = explode("|", $active);
			foreach ($status as $s) {
				if ($softfork["status"] == $s) {
					array_push($result, $key);
				}
			}
		}
		else 
		{
			if ($softfork["status"] == $active) {
				array_push($result, $key);
			}
		}
		next($softforks);
	}
	return $result;
}

function GetSoftforks($softforks, $active) {
	$result = array();

	foreach ($softforks as $softfork) {
		if ($softfork["enforce"]["status"] == $active) {
			array_push($result, $softfork["id"]);
		}	
	}
	return $result;
}

function FormatDate($timestamp) {
	return date('m/d/Y H:i:s', $timestamp);
}

function GetBlockVersionCounter($blockVer, $memcache) {
	return $memcache->get($blockVer);
}

?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Litecoin Segregated witness website">
    <meta name="author" content="">
    <link rel="icon" href="favicon.ico">
    <title>Litecoin Segregated Witness Adoption Tracker</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/flipclock.css">
	<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
	<script src="js/flipclock.js"></script>	
  </head>
  <body>
  <div class="container">
      <div class="page-header" align="center">
        <h1>Is Segregated Witness Active? <b><?=$segwitActive ? "Yes!" : "No";?></b></h1>
      </div>
      <div align="center">
      <h3>
	 	<?php
	 	echo $displayText;
	 	?>
	 </h3>
	 </div>
	 <br/>
     <?php
     if (!$segwitSignalling)
     { 	
     	?> 
     	<div class="flip-counter clock" style="display: flex; align-items: center; justify-content: center;"></div>
			<script type="text/javascript">
				var clock;

				$(document).ready(function() {
					clock = $('.clock').FlipClock(<?=$blockETA?>, {
						clockFace: 'DailyCounter',
						countdown: true
					});
				});
			</script>
	 	<br/>
	 	<?php 
	 } else {
	 	?>
	 	<div align="center">
	 		<iframe width="560" height="315" src="https://www.youtube.com/embed/zZ9dtZ8lYww" frameborder="0" allowfullscreen></iframe>
	 	</div>
	 	<br/>
	 	<?php
	 }
	 ?>
	 <table class="table table-striped">
	    <tr><td><b>Block range <?="(". BLOCK_TARGET . ")"?></b></td><td align = "right"><?=$blockCount . " - " . ($blockCount - BLOCK_TARGET);?></td></tr>
	    <tr><td><b>Current activated soft forks</b></td><td align = "right"><?=implode(",", $activeSFs)?></td></tr>
	    <tr><td><b>Current pending soft forks</b></td><td align = "right"><?=implode(",", $pendingSFs)?></td></tr>
	    <tr><td><b>Next block retarget</b></td><td align = "right"><?=$nextRetargetBlock;?></td></tr>
	    <tr><td><b>Next block retarget ETA</b></td><td align = "right"><?=GetNextRetargetETA($blockETA);?></td></tr>
	    <tr><td><b>BIP9 miner support (in the last 8064 blocks)</b></td><td align = "right"><?=$bip9Blocks . " (" .number_format(($bip9Blocks / BLOCK_TARGET * 100 / 1), 2) . "%)"; ?></td></tr>
	    <tr><td><b>Segwit status </b></td><td align = "right"><?=$segwitInfo["status"];?></td></tr>
	    <tr><td><b>Segwit activation threshold </b></td><td align = "right">75%</td></tr>
        <tr><td><b>Segwit miner support</b></td><td align = "right"><?=$segwitBlocks . " (" .number_format(($segwitBlocks / BLOCK_TARGET * 100 / 1), 2) . "%)"; ?></td></tr>
	    <tr><td><b>Segwit start time </b></td><td align = "right"><?=FormatDate($segwitInfo["startTime"]);?></td></tr>
	    <tr><td><b>Segwit timeout time</b></td><td align = "right"><?=FormatDate($segwitInfo["timeout"]);?></td></tr>
	 </table>
    </div>
    <div align="center">
    	<img src="../images/logo.png" with="200px", height="150px">&nbsp;
    	<img src="../images/litecoin.png" width="125px" height="125px">
	</div>
	<footer>
		<div class="container" align="center">
			Segwit logo designed by <a href="https://twitter.com/albertdrosphoto" rel="external" target="_blank">@albertdrosphoto</a>
		</div>
	</footer>
</body>
</html>
