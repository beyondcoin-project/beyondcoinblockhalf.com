<?php
include_once("analyticstracking.php");
require_once 'jsonRPCClient.php';

define("BIP9_TOPBITS_BLOCK_VERSION", 0x20000000);
define("CSV_SEGWIT_BLOCK_VERSION",   0x20000003);
define("CSV_BLOCK_VERSION",          0x20000001);
define("SEGWIT_BLOCK_VERSION",       0x20000002);
define("SEGWIT_SIGNAL_START",        1145088);
define("SEGWIT_PERIOD_START",        142);
define("BLOCK_RETARGET_INTERVAL",    2016);
define("BLOCK_SIGNAL_INTERVAL",      8064);
define("BLOCKS_PER_DAY",             576);

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

$nextRetargetBlock = GetNextRetarget($blockCount) * BLOCK_RETARGET_INTERVAL;
$nextSignalPeriodBlock = GetNextSignalPeriod($blockCount) * BLOCK_SIGNAL_INTERVAL;
$signalPeriodStart = $nextSignalPeriodBlock - BLOCK_SIGNAL_INTERVAL;
$blocksSincePeriodStart = $blockCount - $signalPeriodStart;
$activationPeriod = ((int)GetNextSignalPeriod($blockCount) - SEGWIT_PERIOD_START);
$blockETA = ($nextRetargetBlock - $blockCount) / BLOCKS_PER_DAY * 24 * 60 * 60;

$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211) or die("Unable to connect to Memcached.");

if ($blocksSincePeriodStart == 0) {
	$mem->flush();
}

$blockHeight = $mem->get("blockheight");
$blockHeight576 = $mem->get("blockheight_576");
$versions = $mem->get("versions");

if (!$versions)
	$versions = array(BIP9_TOPBITS_VERSION, CSV_BLOCK_VERSION, CSV_SEGWIT_BLOCK_VERSION, SEGWIT_BLOCK_VERSION);

$verbose = false;

if ($blockHeight) {
	if ($blockHeight != $blockCount) {
		for ($i = $blockCount; $i > $blockHeight; $i--) {
			HandleBlockVer($i, $mem, $litecoin, true);
		}
		$mem->set("blockheight", $blockCount);
	} 
} else {
	$mem->set('blockheight', $blockCount);
	for ($i = $blockCount - $blocksSincePeriodStart + 1; $i <= $blockCount; $i++) {
		HandleBlockVer($i, $mem, $litecoin, false);
	}
	$mem->set('versions', $versions);
}

if ($blockHeight576) {
	if ($blockHeight576 != $blockCount) {
		$diff = $blockCount - $blockHeight576;
		for ($i = $blockCount; $i > $blockHeight576; $i--) {
			HandleBlockVer($i, $mem, $litecoin, true, true, true);
		}
		for ($i = $blockCount - BLOCKS_PER_DAY; $i < ($blockCount - BLOCKS_PER_DAY) + $diff; $i++) {
			HandleBlockVer($i, $mem, $litecoin, true, false, true);
		}
		$mem->set("blockheight_576", $blockCount);
	} 
} else {
	$mem->set('blockheight_576', $blockCount);
	for ($i = $blockCount - BLOCKS_PER_DAY + 1; $i <= $blockCount; $i++) {
		HandleBlockVer($i, $mem, $litecoin, false, true, true);
	}
	$mem->set('versions', $versions);
}

if ($verbose) {
	echo '<b>Activation Period Block Summary</b><br/>';
	GetBlockRangeSummary($versions, $mem);
	echo '<br/><b>24 Hour Block Summary</b><br/>';
	GetBlockRangeSummary($versions, $mem, '_576');
}

$segwitBlocks = GetSegwitSupport($versions, $mem);
$segwitBlocksPerDay = GetSegwitSupport($versions, $mem, '_576');
$csvBlocks = GetCSVSupport($versions, $mem);
$csvBlocksPerDay = GetCSVSupport($versions, $mem, '_576');
$segwitPercentage = number_format($segwitBlocksPerDay / BLOCKS_PER_DAY * 100 / 1, 2);
$segwitSignalling = ($blockCount >= SEGWIT_SIGNAL_START) ? true : false;
$segwitStatus = $segwitInfo["status"];
$displayText = "The Segregated Witness (segwit) soft fork will start signalling on block number " . $nextRetargetBlock . ".";
if ($segwitSignalling) {
	$displayText = "The Segregated Witness (segwit) soft fork has started signalling! <br/><br/> Ask your pool to support segwit if it isn't already doing so.";
}

function HandleBlockVer($height, $mem, $rpc, $new, $add=true, $postfix=false) {
	$verbose = $GLOBALS['verbose'];
	$blockVer = GetBlockVersion($height, $rpc);
	if ($postfix)
		$blockVer .= '_576';
	
	if (!in_array($blockVer, $GLOBALS['versions'])) {
		array_push($GLOBALS['versions'], $blockVer);
	}

	if ($new && $verbose) {
		echo 'Processing block. Height: ' . $height . ' with block version ' . $blockVer . '.<br>';
	}

	$result = $mem->get($blockVer);
	if (!$result) {
		if ($verbose)
			echo 'Creating new blockver: ' . $blockVer . '<br>';
		$mem->set($blockVer, 1);
	} else {
		if ($add)
			$result += 1;
		else
			$result -= 1;
		$mem->set($blockVer, $result);
		if ($verbose) 
			echo 'Setting block verion: ' . $blockVer . ' count value to: ' . $result . '<br>';
	}
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
	for ($i = 0; $i < $block; $i += BLOCK_RETARGET_INTERVAL) {
		$iterations++;
	}
	if (($iterations * BLOCK_RETARGET_INTERVAL) == $block) {
		$iterations++;
	}
	return $iterations;
}

function GetNextSignalPeriod($block) {
	$iterations = 0;
	for ($i = 0; $i < $block; $i += BLOCK_SIGNAL_INTERVAL) {
		$iterations++;
	}
	if (($iterations * BLOCK_SIGNAL_INTERVAL) == $block) {
		$iterations++;
	}
	return $iterations;
}

function GetBIP9Support($versions, $memcache, $postfix='') {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		if ($version >= BIP9_TOPBITS_VERSION) {
			$totalBlocks += GetBlockVersionCounter($version, $memcache, $postfix);
		}
	}
	return $totalBlocks;
}

function GetCSVSupport($versions, $memcache, $postfix='') {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		if ($version == CSV_BLOCK_VERSION || $version == CSV_SEGWIT_BLOCK_VERSION) {
			$totalBlocks += GetBlockVersionCounter($version, $memcache, $postfix);
		}
	}
	return $totalBlocks;
}

function GetSegwitSupport($versions, $memcache, $postfix='') {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		if ($version == SEGWIT_BLOCK_VERSION || $version == CSV_SEGWIT_BLOCK_VERSION) {
			$totalBlocks += GetBlockVersionCounter($version, $memcache, $postfix);
		}
	}
	return $totalBlocks;
}

function GetBlockRangeSummary($versions, $memcache, $postfix='') {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		$counter = GetBlockVersionCounter($version, $memcache, $postfix);
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

function GetBlockVersionCounter($blockVer, $memcache, $postfix='') {
	return $memcache->get($blockVer . $postfix);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Litecoin Segregated Witness Website">
	<meta name="author" content="">
	<link rel="icon" href="favicon.ico">
	<title>Litecoin Segregated Witness Adoption Tracker</title>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
	<link rel="stylesheet" href="css/flipclock.css">
	<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
	<script src="js/flipclock.js"></script>	
	<style type="text/css">
		.progress {height: 50px; margin-bottom: 0px; margin-top: 30px; }
		img { max-width:375px; height: auto; }
	</style>
</head>
<body>
	<div class="container">
		<div class="page-header" align="center">
			<h1>Is Segregated Witness Active? <b><?=$segwitActive ? "Yes!" : "No";?></b></h1>
		</div>
		<div align="center" style>
			<img src="../images/logo.png">
			<div class="progress">
				<div class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" aria-valuenow="<?=$segwitPercentage?>" aria-valuemin="0" aria-valuemax="100" style="width:<?=$segwitPercentage?>%"></div>
			</div>
			<b>
				<?php
				echo $segwitBlocksPerDay . '/' . BLOCKS_PER_DAY . ' (' . $segwitPercentage . '%)' . ' blocks signaling in the past 24 hours!'; 
				//echo $segwitBlocks . ' blocks signaling! ' . (BLOCK_SIGNAL_INTERVAL * .75) . ' out of '. BLOCK_SIGNAL_INTERVAL . ' (75%) blocks are required to activate.';
				?>
			</b>
		</div>
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
		}
		?>
		<table class="table table-striped" style="margin-top: 30px">
			<tr><td><b>Activation period #<?=$activationPeriod;?> block range <?="(". BLOCK_SIGNAL_INTERVAL . ")"?></b></td><td align = "right"><?=$signalPeriodStart . " - " . $nextSignalPeriodBlock;?></td></tr>
			<tr><td><b>Current block height</b></td><td align = "right"><?=$blockCount;?></td></tr>
			<tr><td><b>Blocks mined since period start</b></td><td align = "right"><?=$blocksSincePeriodStart?></td></tr>
			<tr><td><b>Current activated soft forks</b></td><td align = "right"><?=implode(",", $activeSFs)?></td></tr>
			<tr><td><b>Current pending soft forks</b></td><td align = "right"><?=implode(",", $pendingSFs)?></td></tr>
			<tr><td><b>Next block retarget</b></td><td align = "right"><?=$nextRetargetBlock;?></td></tr>
			<tr><td><b>Blocks to mine until next retarget</b></td><td align = "right"><?=$nextRetargetBlock-$blockCount;?></td></tr>
			<tr><td><b>Next block retarget ETA</b></td><td align = "right"><?=GetNextRetargetETA($blockETA);?></td></tr>
			<tr><td><b>Segwit status </b></td><td align = "right"><?=$segwitStatus;?></td></tr>
			<tr><td><b>Segwit activation threshold </b></td><td align = "right">75%</td></tr>
			<tr><td><b>Segwit miner support within the last 24 hours (last 576 blocks)</b></td><td align = "right"><?=$segwitBlocksPerDay . " (" .number_format(($segwitBlocksPerDay / BLOCKS_PER_DAY * 100 / 1), 2) . "%)"; ?></td></tr>
			<tr><td><b>Segwit miner support (percentage of segwit blocks signaling within the current activation period)</b></td><td align = "right"><?=$segwitBlocks . " (" .number_format(($segwitBlocks / $blocksSincePeriodStart * 100 / 1), 2) . "%)"; ?></td></tr>
			<tr><td><b>CSV miner support within the last 24 hours (last 576 blocks)</b></td><td align = "right"><?=$csvBlocksPerDay . " (" .number_format(($csvBlocksPerDay / BLOCKS_PER_DAY * 100 / 1), 2) . "%)"; ?></td></tr>
			<tr><td><b>CSV miner support (percentage of CSV blocks signaling within the current activation period)</b></td><td align = "right"><?=$csvBlocks . " (" .number_format(($csvBlocks / $blocksSincePeriodStart * 100 / 1), 2) . "%)"; ?></td></tr>
		</table>
	</div>
	<div align="center">
		<h3>
			<?php
			echo $displayText;
			?>
		</h3>
		<br/>
		<img src="../images/litecoin.png" width="125px" height="125px">
	</div>
	<br/>
	<footer>
		<div class="container" align="center">
			Segwit logo designed by <a href="https://twitter.com/albertdrosphoto" rel="external" target="_blank">@albertdrosphoto</a>
		</div>
	</footer>
</body>
</html>
