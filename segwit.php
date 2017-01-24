<?php
include_once("analyticstracking.php");
require_once 'jsonRPCClient.php';

define("BIP9_TOPBITS_VERSION", 0x20000000);
define("TARGET_BLOCK_VERSION", 0x20000003);
define("BLOCK_TARGET",  2016);

$litecoin = new jsonRPCClient('http://user:pw@127.0.0.1:1337/');
$blockCount = $litecoin->getblockcount();
$blockChainInfo = $litecoin->getblockchaininfo(); 

$activeSFs = GetSoftforks($blockChainInfo["softforks"], 1); 
$activeBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], true);
$activeSFs = array_merge($activeSFs, $activeBIP9SFs);

$pendingSFs = GetSoftforks($blockChainInfo["softforks"], 0); 
$pendingBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], false);
$pendingSFs = array_merge($pendingSFs, $pendingBIP9SFs);

$segwitInfo = $blockChainInfo["bip9_softforks"]["segwit"]; 
$segwitActive = ($segwitInfo["status"] == "active") ? true : false; 

$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211) or die("Unable to connect to Memcached.");

$blockHeight = $mem->get("blockheight");
$versions = $mem->get("versions");

if (!$versions)
	$versions = array(BIP9_TOPBITS_VERSION, TARGET_BLOCK_VERSION);

$verbose = false;

if ($blockHeight) {
	if ($blockHeight != $blockCount) {
		$diff = $blockCount - $blockHeight;
		if ($verbose)
			echo 'Diff is: ' . $diff . '<br>';

		for ($i = $blockCount; $i != ($blockCount - $diff); $i--) {
			$blockVer = GetBlockVersion($i, $litecoin);
			if ($verbose)
				echo 'New block. Height: ' . $i . ' with block version ' . $blockVer . '.<br>';
			$result = $mem->get($blockVer);
			$mem->set($blockVer, $result+1);	
		}
		
		$target = ($blockCount - BLOCK_TARGET) + $diff;
		for ($i = ($blockCount - BLOCK_TARGET); $i < $target; $i++) {
			if ($verbose)
				echo 'i is  : ' . $i . ' target is ' . $target . '<br>';
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
	for ($i = $blockCount; $i != ($blockCount - BLOCK_TARGET); $i--) {
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

$segwitBlocks = GetBlockVersionCounter(BIP9_TOPBITS_VERSION, $mem) +  GetBlockVersionCounter(TARGET_BLOCK_VERSION, $mem);

function GetBlockRangeSummary($versions, $memcache) {
	echo 'Current block height: ' . $memcache->get('blockheight') . '<br>';
	$totalBlocks = 0;
	foreach ($versions as $version) {
		$counter = GetBlockVersionCounter($version, $memcache);
		if ($counter == 0) {
			continue;
		}
		echo $counter . ' version ' . $version . ' blocks. <br>';
		$totalBlocks += $counter;
	}
	echo $totalBlocks . ' total blocks. <br>';
}

function GetBlockVersion($blockNum, $rpc) {
	$blockhash = $rpc->getblockhash($blockNum);
	$block = $rpc->getblock($blockhash);
	return $block['version'];
}

function GetBlockVersions($blockCount, $memcache) {
	$versions = array(BIP9_TOPBITS_VERSION, TARGET_BLOCK_VERSION);
	for ($i = $blockCount; $i != ($blockCount - BLOCK_TARGET); $i--) {
		$blockVer = $memcache->get($i);
		if (in_array($blockVer, $versions)) {
			continue;
		} else {
			array_push($versions, $blockVer);
		}
	}
	return $versions;
}

function GetBIP9Softforks($softforks, $active) {
	$result = array();

	while ($softfork = current($softforks)) {
		$key = key($softforks);
		if ($softfork["status"] == $active) {
			array_push($result, $key);
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
    <meta name="description" content="Litecoin Blockhalving countdown website">
    <meta name="author" content="">
    <link rel="icon" href="favicon.ico">
    <title>Litecoin Blockhalving Countdown</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
  </head>
  <body>
  <div class="container">
      <div class="page-header" align="center">
        <h1>Is Segregated Witness Active? <b><?=$segwitActive ? "Yes!" : "No";?></b></h1>
      </div>
	<br/>
	 <p>The Segregated Witness (segwit) soft fork will start signalling on the 1st of Janurary, 2017. The table below shows you current Litecoin blockchain information.</p>
	 <br/>
	 <table class="table table-striped">
	    <tr><td><b>Block range <?="(". BLOCK_TARGET . ")"?></b></td><td align = "right"><?=$blockCount . " - " . ($blockCount - BLOCK_TARGET);?></td></tr>
	    <tr><td><b>Current activated soft forks</b></td><td align = "right"><?=implode(",", $activeSFs)?></td></tr>
	    <tr><td><b>Current pending soft forks</b></td><td align = "right"><?=implode(",", $pendingSFs)?></td></tr>
	    <tr><td><b>Segwit status </b></td><td align = "right"><?=$segwitInfo["status"];?></td></tr>
	    <tr><td><b>Segwit activation threshold </b></td><td align = "right">75%</td></tr>
            <tr><td><b>Segwit miner support</b></td><td align = "right"><?=$segwitBlocks . " (" .number_format(($segwitBlocks / BLOCK_TARGET * 100 / 1), 2) . "%)"; ?></td></tr>
	    <tr><td><b>Segwit start time </b></td><td align = "right"><?=FormatDate($segwitInfo["startTime"]);?></td></tr>
	    <tr><td><b>Segwit timeout time</b></td><td align = "right"><?=FormatDate($segwitInfo["timeout"]);?></td></tr>
	 </table>
    </div>
    <div align="center">
    	<img src="../images/litecoin.png" width="100px"; height="100px">
	</div>
</body>
</html>
