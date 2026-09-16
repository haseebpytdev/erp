<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/app/Services/Operations/ServerSidebarComposer.php';
$composer = new App\Services\Operations\ServerSidebarComposer();
$html = '<ul class="nav flex-column"><li><a href="/outside">Outside</a></li></ul><aside class="sidebar"><ul id="main-nav"><li><a href="/operations/bookings">Bookings</a></li><li><a href="/dashboard">Dashboard</a></li></ul></aside>';
$out = $composer->compose($html);
if (strpos($out, '/outside">Outside</a></li></ul>') === false || strpos($out, 'data-et-server-sidebar="1"') === false) exit(1);
if (strpos($out, '/dashboard') > strpos($out, '/operations/bookings')) exit(1);
echo "PASS server sidebar composer regression\n";
