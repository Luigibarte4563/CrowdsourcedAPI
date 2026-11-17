<?php
$lines = file('C:\xampp\htdocs\CrowdsourcedAPI/api/maintenance/update.php');
for ($i = 40; $i <= 52; $i++) {
    echo $i . ': ' . json_encode($lines[$i-1]) . PHP_EOL;
}