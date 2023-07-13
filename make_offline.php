#!/usr/bin/env php
<?php


// do we want to build a phar?
const pharfile = __DIR__."/tinyfilemanager-offline.phar";
$build_phar = isset(getopt("p")["p"]);


echo "Building offline mode...\n";

if (is_dir("offline")) bomb("offline directory already exists");

$basedir = "offline/";
$assetdir = $basedir . "assets/";


// First, let's see if we can find what we're looking for.


$contents = file_get_contents("tinyfilemanager.php");

// find highlight_js_style
if (preg_match("/(\\\$highlightjs_style =.*;)/sU", $contents, $h)) {
	eval($h[1]);

	if (!isset($highlightjs_style)) bomb("couldn't eval \$highlightjs_style");
} else {
	bomb("couldn't find \$highlightjs_style");
}

// find the external array
if (preg_match("/(\\\$external = .*;)/sU", $contents, $m, PREG_OFFSET_CAPTURE)) {
	list($ext_var, $ext_pos) = $m[1];

	eval($ext_var);
	if (!is_array($external)) bomb("couldn't parse \$external");
} else {
	bomb("couldn't find \$external");
}

// if we made it here, we found it.  start writing stuff
if (!mkdir($assetdir, 0755, true)) bomb("couldn't create directories");
if (!copy("translation.json", $basedir."translation.json")) bomb("couldn't move translations");


// Second, let's grab all the files.
$new = [];

foreach ($external as $idx=>$html) {
	if (preg_match("/^css/", $idx)) {
		// is css
		if (!preg_match("/href=\"(.*)\"/U", $html, $h)) bomb("couldn't find css href");
		$base = bring_asset($h[1]);
		$new[$idx] = sprintf('<link href="assets/%s" rel="stylesheet">', $base);


		// this part is mainly for grabbing font awesome stuff.
		// it is probably the weakest of all of this.
		$css = file_get_contents($assetdir.$base);

		preg_match_all("/url\((.*)\)/sU", $css, $m);
		foreach ($m[1] as $match) {
			$clean = strtok(str_replace(['"', "'"], "", $match), '?');
			if (preg_match("/^data/", $clean)) continue;
			$exturl = dirname($h[1]).'/'.$clean;
			$css = str_replace($match, bring_asset($exturl), $css);
		}

		file_put_contents($assetdir.$base, $css);

	} else if (preg_match("/^js/", $idx)) {
		// is js
		if (!preg_match("/src=\"(.*)\"/U", $html, $h)) bomb("couldn't find js src");
		$new[$idx] = sprintf('<script src="assets/%s"></script>', bring_asset($h[1]));
	} else {
		// is pre and can skip
		$new[$idx] = "";
	}
}



// Third, make a new version of the main file with the new stuff in it
$newcode = '$external = (array) json_decode(<<<EndJSON'."\n".
	json_encode($new, JSON_PRETTY_PRINT).
	"\nEndJSON\n);\n\n";

$new_content = str_replace($ext_var, $newcode, $contents);
file_put_contents($basedir."index.php", $new_content);

// Okay, are we gonna make a phar out of it?

if ($build_phar) {
	echo "[THIS DOESN'T WORK] Building phar...\n";

	if (file_exists(pharfile)) bomb("phar already exists");

	// create phar
	$phar = new Phar(pharfile);

	// start buffering. Mandatory to modify stub to add shebang
	$phar->startBuffering();
	$phar->buildFromDirectory(__DIR__ . '/offline');


	$phar->setStub("<?php\nPhar::webPhar();\n__HALT_COMPILER();\n?>");

	$phar->stopBuffering();
	$phar->compressFiles(Phar::GZ);

	# Make the file executable
	//chmod(pharfile, 0770);

	printf("%s successfully created\n", pharfile);

}

echo "Done.\n";


// helper funcs

function bomb(string $cause, int $val = 1) {
	echo "offlineize: $cause\n";
	exit($val);
}

function bring_asset(string $url): string {
	global $assetdir;
	$base = basename($url);
	if (!file_put_contents($assetdir.$base, file_get_contents($url)))
		bomb("couldn't retrieve asset: $base");
	return $base;

}
