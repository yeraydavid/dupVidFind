<?php
/**
* DupVidFind 1.0
* Script for recursive finding of equivalent video files. 
* It looks for videos that has the same content, even with different format, quality or size.
* With thousand of videos, the comparison can last a few hours, but is totally unattended. 
*
* Execute with the PHP command line, or adapt it to your own application.
* Usage example: c:\xampp\php\php.exe dupvidfind.php c:\folder_with_videos c:\temp_folder
*
* WARNING: Don't forget to specify the path to your FFMPEG directory, line #14
*
* This script is provided without any guarantee, and I will not assume any responsibility 
* for the use or misuse of it. Use it at your own risk.
*
* Published by Yeray David Rodriguez Dominguez under GPLv3 licence (http://www.gnu.org/copyleft/gpl.html)
* Copyright Yeray David Rodriguez Dominguez
* Comments and suggestions: yeray_at_yeray_dot_com
*/

$ffmpeg = "ffmpeg\\bin\\ffmpeg"; // FFMPEG path

if(!isset($argv[1], $argv[2]))
{
	echo("dupvidfind is an equivalent video finder. It looks for videos that has the same\ncontent, even with different format, quality or size.\n\n");
	echo("Use: dupvidfind videos_path thumbs_path [tolerance] \n");
	echo("  videos_path: directory to look for equivalent videos.\n");
	echo("  thumbs_path: directory where the samples used in the process will be stored.\n");
	echo("  tolerance:   amount of difference allowed for the matching.\n");
	echo("               Lower tolerance values will generate less matches. Default:5\n");
	exit;
}

$vpath = $argv[1];
$tmppath = $argv[2];

$num = 5;               // Samples per file
$sep = 30;              // Samples distance in second
$thumbsize = "64:48";   // Samples size
$umbral_color = 15;     // Threshold to consider two pixels as equal
$extensiones = Array ( 'flv', 'mp4', 'avi', 'mkv' ); // video extensionss

if(isset($argv[3]))
	$tolerancia = intval($argv[3]) / 100; // Tolerance level in image comparison
else
	$tolerancia = 0.05;

// Based on http://www.robert-lerner.com/code/image-compare
function image_compare($image1, $image2)
{
	global $umbral_color;
	if (is_resource($image1))
		$im = $image1;
	else
		if (!$im = imagecreatefromjpeg($image1))
			trigger_error("Image 1 could not be opened"); 
	if (!$im2 = imagecreatefromjpeg($image2))
		trigger_error("Image 2 could not be opened");	  
	$p = 0;
	$ix = imagesx($im);
	$iy = imagesy($im);
	$total = ($ix*$iy)*3;
	for ($width=0;$width<=$ix-1;$width++)
	{
		for ($height=0;$height<=$iy-1;$height++)
		{
			$rgb = imagecolorat($im, $width, $height);
			$r1 = ($rgb >> 16) & 0xFF;
			$g1 = ($rgb >> 8) & 0xFF;
			$b1 = $rgb & 0xFF;
			  
			$rgb = imagecolorat($im2, $width, $height);
			$r2 = ($rgb >> 16) & 0xFF;
			$g2 = ($rgb >> 8) & 0xFF;
			$b2 = $rgb & 0xFF;
			 
			if (!($r1>=$r2-$umbral_color && $r1<=$r2+$umbral_color)) $p++;
			if (!($g1>=$g2-$umbral_color && $g1<=$g2+$umbral_color)) $p++;
			if (!($b1>=$b2-$umbral_color && $b1<=$b2+$umbral_color)) $p++;
		}
	}	
	$distancia = ($p/$total);
	return $distancia;
}

// Based on the Rommel note at http://php.net/manual/es/function.filesize.php
function human_filesize($f, $decimals = 2) {
	$bytes = filesize($f);
    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function getInfo($file)
{
	global $ffmpeg;
	$xyz =  shell_exec("$ffmpeg -i \"$file\"  2>&1");   
	$search='/Duration: (.*?),/';
	preg_match($search, $xyz, $matches);
	$explode = explode(':', $matches[1]);
	$duration = intval($explode[2] + 60*$explode[1] + 3600*$explode[0]);
	preg_match('/\s(?<width>\d+)[x](?<height>\d+)\s\[/', $xyz, $ma); 
	$obj = array();
	$obj["duracion"] = $duration;
	if(isset($ma['width'],$ma['height']))
		$obj["dims"] = $ma['width']."x".$ma['height'];
	else
		$obj["dims"] = "?";
		
	return $obj;
}

function fabricaArrayFicheros($path, $exts)
{
	$files = array();
	$it = new RecursiveDirectoryIterator($path);
	foreach(new RecursiveIteratorIterator($it) as $file)
	{
		$ext = $file->getExtension();
		$siz = filesize($file->getRealPath());
		if (in_array($ext, $exts) && ($siz != 0))
			$files[] = $file;
	}
	return $files;
}

function nameconv($file,$i)
{
	global $tmppath;
	$p = str_replace("/","@~",$file->getPathname())."-$i.jpg";
	$p = str_replace("\\","@~",$file->getPathname())."-$i.jpg";
	$p = str_replace(":","@$",$p);
	return $tmppath.DIRECTORY_SEPARATOR.$p;
}

function procesaFile($file)
{
	global $ffmpeg, $num, $sep, $thumbsize, $tmppath;
	$name = $file->getFilename();
	$path = $file->getRealPath();			
	echo "file ".$name;
	$info = getInfo($path);
	$duration = $info["duracion"];
	echo " ($duration sec) ";
	for($i=1; $i<=$num; $i++)
	{
		$sec = $i * $sep;
		if($sec > $duration)
		{
			if($i==1) 
				$sec = intval($duration / 2);
			else
				continue;
		}
		$outf = nameconv($file,$i);
		if(!file_exists($outf))
		{
			$run = "$ffmpeg -loglevel fatal -i \"$path\" -vf scale=$thumbsize,boxblur=4:1 -ss $sec -f image2 -vframes 1 \"$outf\"";
			exec($run);
		}		
		echo ".";
	}
	echo "Ok\n";
}


echo "\n1: Files exploration\n";
$files = fabricaArrayFicheros($vpath,$extensiones);
$fc = count($files);
echo $fc." files found.\n";

echo "\n2: Samples generation\n";
$nn=1;
foreach($files as $file)
{
	echo "(".$nn."/".$fc.") ";
	procesaFile($file);
	$nn++;
}

echo "\n3: Matching\n";
$grupos = array();
for($i=0; $i<$fc-1; $i++)
{
	if($files[$i] == null)
		continue;
	$imagescache = array();
	for($k=1; $k<=$num; $k++)
	{
		$file1 = nameconv($files[$i],$k);
		if(file_exists($file1))
			$imagescache[$k] = imagecreatefromjpeg($file1);
		else
			break;		
	}
	for($j=$i+1; $j<$fc; $j++)
	{
		if($files[$j] == null)
			continue;	
		$grupo = array();
		echo "(".($i+1)."/$fc) Matching ".$files[$i]->getFilename()." with ".$files[$j]->getFilename().": ";
		$grupo[] = $files[$i];
		$maxdif = -1;
		for($k=1; $k<=$num; $k++)
		{
			$file2 = nameconv($files[$j],$k);
			if(((file_exists($file2)) && (isset($imagescache[$k]))) && ($maxdif <= $tolerancia))
			{
				$res = image_compare($imagescache[$k], $file2);
				if($res > $maxdif)
					$maxdif = $res;
			}
			else
				break;
		}
		if($maxdif != -1)
		{
			echo intval(100-$maxdif*100)."%";
			if($maxdif <= $tolerancia)
			{
				echo " MATCH\n";
				$grupo[] = $files[$j];
				$files[$j] = null;			
			}
			else
				echo " Not match\n";
		}
		else
			echo " No samples\n";
		$grupos[] = $grupo;
	}
}


echo "\n4: Results\n";
$mat = 0;
for($i=0; $i<count($grupos); $i++)
{
	$grupo = $grupos[$i];
	if(count($grupo) == 1)
		continue;
	$mat++;
	echo "\nGrupo $mat:\n";	
	for($j=0; $j<count($grupo); $j++)
	{
		$vid = $grupo[$j];
		$info = getInfo($vid->getRealPath());
		$dur = $info["duracion"];
		while(strlen($dur) < 4)
			$dur.=" ";		
		$dim = $info["dims"];
		while(strlen($dim) < 9)
			$dim.=" ";
		$siz = human_filesize($vid->getRealPath(),0);
		while(strlen($siz) < 6)
			$siz.=" ";		
		echo " $dur $dim $siz ".$vid->getRealPath()."\n";
	}
}

?>