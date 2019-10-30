<?php
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

$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211) or die("Unable to connect to Memcached.");

$beyondcoin = new jsonRPCClient('http://user:pass@127.0.0.1:10332/');
$blockCount = $beyondcoin->getblockcount();
$blockChainInfo = $beyondcoin->getblockchaininfo();

$activeSFs = GetSoftforks($blockChainInfo["softforks"], true);
$activeBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], "active");
$activeSFs = array_merge($activeSFs, $activeBIP9SFs);

$pendingSFs = GetSoftforks($blockChainInfo["softforks"], false);
$pendingBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], "defined|started|locked_in");
$pendingSFs = array_merge($pendingSFs, $pendingBIP9SFs);

$segwitInfo = $blockChainInfo["bip9_softforks"]["segwit"];
$segwitActive = ($segwitInfo["status"] == "active") ? true : false;

$nextRetargetBlock = GetNextRetarget($blockCount) * BLOCK_RETARGET_INTERVAL;
$nextSignalPeriodBlock = GetNextSignalPeriod($blockCount) * BLOCK_SIGNAL_INTERVAL;
$signalPeriodStart = $nextSignalPeriodBlock - BLOCK_SIGNAL_INTERVAL;
$blocksSincePeriodStart = $blockCount - $signalPeriodStart;
$activationPeriod = ((int)GetNextSignalPeriod($blockCount) - SEGWIT_PERIOD_START);
$blockETA = (BLOCK_SIGNAL_INTERVAL - $blocksSincePeriodStart) / BLOCKS_PER_DAY * 24 * 60 * 60;
$blocksSincePeriodStartPercentage = number_format($blocksSincePeriodStart / BLOCK_SIGNAL_INTERVAL * 100 / 1, 2);

$period = $mem->get("period");
if (!$period) {
	$mem->set("period", $activationPeriod);
} else {
	if ($period != $activationPeriod) {
		$mem->flush();
		$mem->set("period", $activationPeriod);
	}
}

$blockHeight = $mem->get("blockheight");
$blockHeight576 = $mem->get("blockheight_576");
$blockHeight8064 = $mem->get("blockheight_8064");
$versions = $mem->get("versions");
if (!$versions)
	$versions = array(BIP9_TOPBITS_BLOCK_VERSION, CSV_BLOCK_VERSION, CSV_SEGWIT_BLOCK_VERSION, SEGWIT_BLOCK_VERSION);

$verbose = false;
$jsonOutput = false;

if ($_GET['q'] == "json") {
	header('Content-Type: application/json');
	$verbose = false;
	$jsonOutput = true;
} else {
	include_once("analyticstracking.php");
}

CheckBlocks($blockHeight, $blockCount, $mem, $beyondcoin, $blocksSincePeriodStart);
CheckBlocks($blockHeight576, $blockCount, $mem, $beyondcoin, BLOCKS_PER_DAY, '_576');
CheckBlocks($blockHeight8064, $blockCount, $mem, $beyondcoin, BLOCK_SIGNAL_INTERVAL, '_8064');
$mem->set('versions', $versions);

if ($verbose) {
	echo '<b>Activation Period Block Summary</b><br/>';
	GetBlockRangeSummary($versions, $mem);
	echo '<br/><b>24 Hour Block Summary</b><br/>';
	GetBlockRangeSummary($versions, $mem, '_576');
	echo '<br/><b>8064 Block Summary</b><br/>';
	GetBlockRangeSummary($versions, $mem, '_8064');
}

$segwitBlocks = GetSegwitSupport($versions, $mem);
$segwitBlocksPerDay = GetSegwitSupport($versions, $mem, '_576');
$csvBlocks = GetCSVSupport($versions, $mem);
$csvBlocksPerDay = GetCSVSupport($versions, $mem, '_576');
$segwitPercentage = number_format($segwitBlocks / $blocksSincePeriodStart * 100 / 1, 2);
$segwitSignalling = ($blockCount >= SEGWIT_SIGNAL_START) ? true : false;
$segwitStatus = $segwitInfo["status"];
$displayText = "The Segregated Witness (segwit) soft fork will start signalling on block number " . $nextRetargetBlock . ".";
if ($segwitSignalling) {
	$displayText = "The Segregated Witness (segwit) soft fork has started signalling! <br/><br/> Ask your pool to support segwit if it isn't already doing so.";
}
if ($segwitStatus == "locked_in" || $segwitStatus == "active") {
	$displayText = "";
}

if ($jsonOutput) {
	$response = array(
		"last576" => array(
			"total" => BLOCKS_PER_DAY,
			"fromHeight" => $blockCount-BLOCKS_PER_DAY,
			"toHeight" => $blockCount,
			"stats" => GetBlockRangeStats($versions, BLOCKS_PER_DAY, $mem, '_576')
			),
		"last8064" => array(
			"total" => BLOCK_SIGNAL_INTERVAL,
			"fromHeight" => $blockCount-BLOCK_SIGNAL_INTERVAL,
			"toHeight" => $blockCount,
			"stats" => GetBlockRangeStats($versions, BLOCK_SIGNAL_INTERVAL, $mem, '_8064')
			),
		"sincePeriodStart" => array(
			"total" => $blocksSincePeriodStart,
			"fromHeight" => $signalPeriodStart,
			"toHeight" => $blockCount,
			"stats" => GetBlockRangeStats($versions, $blocksSincePeriodStart, $mem)
			)
		);
	echo json_encode($response, JSON_PRETTY_PRINT);
	die();
}

function CheckBlocks($blockHeight, $blockCount, $mem, $rpc, $blockTarget, $postfix='') {
	$blockHeightStr = "blockheight" . $postfix;
	if ($blockHeight) {
		if ($blockHeight != $blockCount) {
			$diff = $blockCount - $blockHeight;
			for ($i = $blockCount; $i > $blockHeight; $i--) {
				HandleBlockVer($i, $mem, $rpc, true, true, $postfix);
			}
			if ($postfix != '') {
				for ($i = $blockCount - $blockTarget; $i < ($blockCount - $blockTarget) + $diff; $i++) {
					HandleBlockVer($i, $mem, $rpc, true, false, $postfix);
				}
			}
			$mem->set($blockHeightStr, $blockCount);
		}
	}
	else {
		$mem->set($blockHeightStr, $blockCount);
		for ($i = $blockCount - $blockTarget + 1; $i <= $blockCount; $i++) {
			HandleBlockVer($i, $mem, $rpc, false, true, $postfix);
		}
	}
}

function HandleBlockVer($height, $mem, $rpc, $new, $add=true, $postfix='') {
	$verbose = $GLOBALS['verbose'];
	$blockVer = GetBlockVersion($height, $rpc);
	if ($postfix)
		$blockVer .= $postfix;

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
		if ($version >= BIP9_TOPBITS_BLOCK_VERSION) {
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

function GetBlockRangeStats($versions, $amount, $memcache, $postfix='') {
	$stats = array();
	foreach ($versions as $version) {
		$counter = GetBlockVersionCounter($version, $memcache, $postfix);
		if ($counter == 0) {
			continue;
		}
		$stats[] = array(
			"version" => $version,
			"count" => $counter,
			"percentage" => $counter / $amount * 100 / 1
			);
	}

	$segwitCount = GetSegwitSupport($versions, $memcache, $postfix);
	$stats[] = array(
		"proposal" => "SEGWIT",
		"count" => $segwitCount,
		"percentage" => $segwitCount / $amount * 100 / 1
		);
	return $stats;
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
		if ($softfork["reject"]["status"] == $active) {
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

function GetSegwitStatus($status)
{
    switch ($status) {
        case "active":
            return "Yes! :)";
            break;
        case "locked_in":
            return "Almost!";
            break;
        default:
            return "No";
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Beyondcoin Segregated Witness Website">
	<meta name="author" content="">
	<link rel="icon" href="favicon.ico">
	<title>Beyondcoin Segregated Witness Adoption Tracker</title>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
	<link rel="stylesheet" href="css/flipclock.css">
	<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
	<script src="js/flipclock.js"></script>
	<style type="text/css">
		.progress {height: 50px; margin-bottom: 0px; margin-top: 30px; }
	</style>
</head>
<body>
	<div class="container">
		<div class="page-header" align="center">
			<h1>Is Segregated Witness Active? <b><?=GetSegwitStatus($segwitStatus);?></b></h1>
		</div>
		<div align="center" style>
			<img src="../images/logo.png" style="max-width:375px; height: auto;">
			<br/><br/>
				<?php
						$text = "";
						if ($segwitStatus == "locked_in") {
							$text = 'SegWit for Beyondcoin has succesfully locked in! A big thank you to the Beyondcoin/Bitcoin communities, the Beyondcoin/Bitcoin developers and the miners for making this possible! After activation period 7, SegWit for Beyondcoin will be active.';
						} else if ($segwitStatus == "active") {
							$text = 'SegWit for Beyondcoin has succesfully activated! A big thank you to the Beyondcoin/Bitcoin communities, the Beyondcoin/Bitcoin developers and the miners for making this possible! Arise chickun!';
						}
						echo '<b>'. $text . '</b>';
				 ?>
			<?php
			if ($segwitStatus == "locked_in" || $segwitStatus == "started")
			{
				?>
				<div class="progress">
					<div class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" aria-valuenow="<?=$blocksSincePeriodStartPercentage?>" aria-valuemin="0" aria-valuemax="100" style="width:<?=$blocksSincePeriodStartPercentage?>%"></div>
				</div>
				<b>
					<?php
					if ($segwitStatus == "locked_in") {
						$blocksLeft = BLOCK_SIGNAL_INTERVAL-$blocksSincePeriodStart;
						echo $blocksSincePeriodStart . '/' . BLOCK_SIGNAL_INTERVAL . ' (' . $blocksSincePeriodStartPercentage . '%) of period mined. ' . $blocksLeft . ' blocks left until SegWit is active.' . "<br/>";
					} else if ($segwitStatus == "started") {
						echo $segwitBlocks . '/' . $blocksSincePeriodStart. ' ('. $segwitPercentage . '%) blocks signaling! ' . (BLOCK_SIGNAL_INTERVAL * .75) . ' out of '. BLOCK_SIGNAL_INTERVAL . ' (75%) blocks are required to reach "locked_in" status.<br/> After another activation period, SegWit will become active.';
						//echo $segwitBlocksPerDay . '/' . BLOCKS_PER_DAY . ' (' . $segwitPercentage . '%)' . ' blocks signaling in the past 24 hours!';
					}
				echo '</b>';
			} else if ($segwitStatus == "active") {
				?>
				<br/><br/>
				<img src="../images/megachickun.jpg" style="margin-top: 10px; width:100%"></img>
				<?php
			}
			?>
		</div>
		<?php
		if (!$segwitSignalling || $segwitStatus == "locked_in")
		{
			?>
			<br/><br/>
			<div class="flip-counter clock" style="display: flex; align-items: center; justify-content: center; margin:0"></div>
			<script type="text/javascript">
			var clock;
			$(document).ready(function() {
				clock = new FlipClock($('.clock'), <?=$blockETA?>, {
					clockFace: 'DailyCounter',
					autoStart: true,
					countdown: true,
					callbacks: {
						stop: function() {
							location.reload()
						}
					}
				});
			});
			</script>
			<?php
		}
		?>
		<table class="table table-striped" style="margin-top: 30px">
			<tr><td><b>Activation period #<?=$activationPeriod;?> block range <?="(". BLOCK_SIGNAL_INTERVAL . " blocks)"?></b></td><td align = "right"><?=$signalPeriodStart . " - " . $nextSignalPeriodBlock;?></td></tr>
			<tr><td><b>Current block height</b></td><td align = "right"><?=$blockCount;?></td></tr>
			<tr><td><b>Blocks mined since period start</b></td><td align = "right"><?=$blocksSincePeriodStart . " (". number_format($blocksSincePeriodStart / BLOCK_SIGNAL_INTERVAL * 100 / 1, 2) . "%)"?></td></tr>
			<tr><td><b>Blocks left until period end</b></td><td align = "right"><?=BLOCK_SIGNAL_INTERVAL-$blocksSincePeriodStart . " (". number_format((BLOCK_SIGNAL_INTERVAL-$blocksSincePeriodStart) / BLOCK_SIGNAL_INTERVAL * 100 / 1, 2) . "%)"?></td></tr>
			<tr><td><b>Current activated soft forks</b></td><td align = "right"><?=implode(",", $activeSFs)?></td></tr>
			<tr><td><b>Current pending soft forks</b></td><td align = "right"><?=implode(",", $pendingSFs)?></td></tr>
			<tr><td><b>Next block retarget (4 per activation period)</b></td><td align = "right"><?=$nextRetargetBlock;?></td></tr>
			<tr><td><b>Blocks to mine until next retarget</b></td><td align = "right"><?=$nextRetargetBlock-$blockCount;?></td></tr>
			<tr><td><b>Next block retarget ETA</b></td><td align = "right"><?=GetNextRetargetETA(($nextRetargetBlock - $blockCount) / BLOCKS_PER_DAY * 24 * 60 * 60);?></td></tr>
			<tr><td><b>Segwit status </b></td><td align = "right"><?=$segwitStatus;?></td></tr>
			<tr><td><b>Segwit activation threshold </b></td><td align = "right">75%</td></tr>
		</table>
	</div>
	<div align="center">
		<?php
		if ($displayText != "") {
			echo '<h4><b>' . $displayText . '</b></h4>';
		}
		?>
		<img src="../images/beyondcoin.png" width="125px" height="125px">
	</div>
	<br/>
	<footer>
		<div class="container" align="center">
			Segwit logo designed by <a href="https://twitter.com/albertdrosphoto" rel="external" target="_blank">@albertdrosphoto</a>
		</div>
	</footer>
</body>
</html>
