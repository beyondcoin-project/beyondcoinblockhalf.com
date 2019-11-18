<?php
include_once("analyticstracking.html");
require_once 'jsonRPCClient.php';

$beyondcoin = new jsonRPCClient('http://user:pw@127.0.0.1:10332/');

try {
	$info = $beyondcoin->getinfo();
} catch (Exception $e) {
	echo nl2br($e->getMessage()).'<br />'."\n"; 
	die();
}

// Beyondcoin settings
$blockStartingReward = 50;
$blockHalvingSubsidy = 840000;
$blockTargetSpacing = 2.5;
$maxCoins = 84000000;

$blocks = $info['blocks'];
$coins = CalculateTotalCoins($blockStartingReward, $blocks, $blockHalvingSubsidy);
$blocksRemaining = CalculateRemainingBlocks($blocks, $blockHalvingSubsidy);
$blocksPerDay = (60 / $blockTargetSpacing) * 24;
$blockHalvingEstimation = $blocksRemaining / $blocksPerDay * 24 * 60 * 60;
$blockHalvings = GetHalvings($blocks, $blockHalvingSubsidy);
$blockString = '+' . $blockHalvingEstimation . ' second';
$blockReward = CalculateRewardPerBlock($blockStartingReward, $blocks, $blockHalvingSubsidy);
$coinsRemaining = $blocksRemaining * $blockReward;
$nextHalvingHeight = $blocks + $blocksRemaining;
$inflationRate = CalculateInflationRate($coins, $blockReward, $blocksPerDay);
$inflationRateNextHalving = CalculateInflationRate(CalculateTotalCoins($blockStartingReward, $nextHalvingHeight, $blockHalvingSubsidy), 
	CalculateRewardPerBlock($blockStartingReward, $nextHalvingHeight, $blockHalvingSubsidy), $blocksPerDay);
$price = GetPrice(); // change to dynamic way of getting price

function GetPrice() {
	$file = fopen("price.txt", "r") or die("Unable to open file!");
	$result = fread($file,filesize("price.txt"));
	fclose($file);
	return $result;
}

function GetHalvings($blocks, $subsidy) {
	return (int)($blocks / $subsidy);
}

function CalculateRemainingBlocks($blocks, $subsidy) {
	$halvings = GetHalvings($blocks, $subsidy);
	if ($halvings == 0) {
		return $subsidy - $blocks;
	} else {
		$halvings += 1;
		return $halvings * $subsidy - $blocks;
	}
}

function CalculateRewardPerBlock($blockReward, $blocks, $subsidy) {
	$halvings = GetHalvings($blocks, $subsidy);

	if ($halvings == 0) {
		return $blockReward;
	}

	for ($i = 0; $i < $halvings; $i++) {
		$blockReward = $blockReward / 2;
	}

	return $blockReward;
}

function CalculateTotalCoins($blockReward, $blocks, $subsidy) {
	$halvings = GetHalvings($blocks, $subsidy);
	if ($halvings == 0) {
		return $blocks * $blockReward;
	} else {
		$coins = 0;
		for ($i = 0; $i < $halvings; $i++) {
			$coins += $blockReward * $subsidy;
			$blocks -= $subsidy;
			$blockReward = $blockReward / 2; 
		}
		$coins += $blockReward * $blocks;
		return $coins;
	}
}

function CalculateInflationRate($totalCoins, $blockReward, $blocksPerDay) {
	return pow((($totalCoins + $blockReward) / $totalCoins), (365 * $blocksPerDay)) - 1;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Beyondcoin Block Reward Halving Countdown website">
	<meta name="author" content="">
	<link rel="apple-touch-icon" sizes="180x180" href="images/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16x16.png">
	<link rel="manifest" href="site.webmanifest">
	<link rel="mask-icon" href="images/safari-pinned-tab.svg" color="#5bbad5">
	<meta name="msapplication-TileColor" content="#da532c">
	<meta name="theme-color" content="#ffffff">
	<title>Beyondcoin Block Reward Halving Countdown</title>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
	<link rel="stylesheet" href="css/flipclock.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
	<script src="js/flipclock.js"></script>	
</head>
<body>
	<div class="container">
		<div class="page-header" style="text-align:center">
			<h3>Beyondcoin Block Reward Halving Countdown</h3>
		</div>
		<div class="flip-counter clock" style="display: flex; align-items: center; justify-content: center; margin:0"></div>
		<script type="text/javascript">
		var clock;
		$(document).ready(function() {
			clock = new FlipClock($('.clock'), <?=$blockHalvingEstimation?>, {
				clockFace: 'DailyCounter',
				autoStart: true,
				countdown: true
			});
		});
		</script>
		<div style="text-align:center">
			Reward-Drop ETA date: <strong><?=date('d M Y H:i:s', strtotime($blockString, time()))?></strong><br/><br/>
			<p>The Beyondcoin block mining reward halves every <?=number_format($blockHalvingSubsidy)?> blocks, the coin reward will decrease from <?=$blockReward?> to <?=$blockReward / 2 ?> coins. <!--You can watch an educational video by the <a href="http://litecoinassociation.org/">Litecoin Association</a> explaining it in more detail below:--></p>
			<!--<iframe width="560" height="315" align="center" src="https://www.youtube.com/embed/BPxq8CgMooI" frameborder="0" allowfullscreen></iframe>-->
			<br/><br/>
		</div>
		<table class="table table-striped">
			<tr><td><b>Total Beyondcoins in circulation:</b></td><td align = "right"><?=number_format($coins)?></td></tr>
			<tr><td><b>Total Beyondcoins to ever be produced:</b></td><td align = "right"><?=number_format($maxCoins)?></td></tr>
			<tr><td><b>Percentage of total Beyondcoins mined:</b></td><td align = "right"><?=number_format($coins / $maxCoins * 100 / 1, 2)?>%</td></tr>
			<tr><td><b>Total Beyondcoins left to mine:</b></td><td align = "right"><?=number_format($maxCoins - $coins)?></td></tr>
			<tr><td><b>Total Beyondcoins left to mine until next blockhalf:</b></td><td align = "right"><?= number_format($coinsRemaining);?></td></tr>
			<tr><td><b>Beyondcoin price (USD):</b></td><td align = "right">$<?=number_format($price, 8);?></td></tr>
			<tr><td><b>Market capitalization (USD):</b></td><td align = "right">$<?=number_format($coins * $price, 2);?></td></tr>
			<tr><td><b>Beyondcoins generated per day:</b></td><td align = "right"><?=number_format($blocksPerDay * $blockReward);?></td></tr>	
			<tr><td><b>Beyondcoin inflation rate per annum:</b></td><td align = "right"><?=number_format($inflationRate * 100 / 1, 2);?>%</td></tr>
			<tr><td><b>Beyondcoin inflation rate per annum at next block halving event:</b></td><td align = "right"><?=number_format($inflationRateNextHalving * 100 / 1, 2);?>%</td></tr>
			<tr><td><b>Beyondcoin inflation per day (USD):</b></td><td align = "right">$<?=number_format($blocksPerDay * $blockReward * $price);?></td></tr>
			<tr><td><b>Beyondcoin inflation until next blockhalf event: (USD):</b></td><td align = "right">$<?=number_format($coinsRemaining * $price);?></td></tr>
			<tr><td><b>Total blocks:</b></td><td align = "right"><?=number_format($blocks);?></td></tr>
			<tr><td><b>Blocks until mining reward is halved:</b></td><td align = "right"><?=number_format($blocksRemaining);?></td></tr>
			<tr><td><b>Total number of block reward halvings:</b></td><td align = "right"><?=$blockHalvings;?></td></tr>
			<tr><td><b>Approximate block generation time:</b></td><td align = "right"><?=$blockTargetSpacing?> minutes</td></tr>
			<tr><td><b>Approximate blocks generated per day:</b></td><td align = "right"><?=$blocksPerDay;?></td></tr>
			<tr><td><b>Difficulty:</b></td><td align = "right"><?=number_format($info['difficulty']);?></td></tr>
			<tr><td><b>Hash rate:</b></td><td align = "right"><?=number_format($beyondcoin->getnetworkhashps() / 1000 / 1000 / 1000) . ' GH/s';?></td></tr>
		</table>
		<div style="text-align:center">
            <img src="images/android-chrome-192x192.png" width="100px"; height="100px">
                <br/>
            <h2><a href="https://beyondcoin.io">Beyondcoin,</a> a coin created to go Beyond the impossible.</h2>
            <span>
			    <a href="https://github.com/beyondcoin-project"><i class="fa fa-github" style="font-size:48px;color:black;" title="GitHub"></i></a>&nbsp;&nbsp;
                <a href="https://twitter.com/Beyondcoin_BYND"><i class="fa fa-twitter" style="font-size:48px;color:black;" title="Twitter"></i></a>&nbsp;&nbsp;
            	<a href="https://instagram.com/beyondcoin"><i class="fa fa-instagram" style="font-size:48px;color:black;" title="Instagram"></i></a>
            </span>
            <br/>
            <span>
                <a href="http://byndexplorer.com"><i class="fa fa-cubes" style="position:relative;font-size:24px;color:grey;top:7.5px;" title="Beyondcoin Block Explorer"></i></a>&nbsp;&nbsp;
                <a href="https://beyondcoinpool.com"><i class="fa fa-server" style="position:relative;font-size:24px;color:grey;top:7.5px;" title="beyondcoinpool.com"></i></a>
            </span>
			<br/><br/><br/>
        </div>
	</div>
</body>
</html>