<?php
/** Synthetic markers only. Never reads existing application logs/secrets. */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Hunter\Support\Logger;
$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'hunter_logger_'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);putenv('HUNTER_PRIVATE_DIR='.$dir);
ini_set('zend.exception_ignore_args','0');
$marker='SYNTHETIC_SECRET_'.bin2hex(random_bytes(12));
function simulatedFailure(string $secret):void { throw new RuntimeException('Synthetic failed request contains '.$secret,17); }
try{simulatedFailure($marker);}catch(Throwable $e){Logger::error($e,'synthetic-error-id');}
$path=$dir.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'app.log';
$raw=file_get_contents($path);$record=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
$safe=!str_contains($raw,$marker)&&!str_contains($raw,'SYNTHETIC_SECRET_');
$useful=$record['error_id']==='synthetic-error-id'&&$record['type']===RuntimeException::class&&!empty($record['trace']);
unlink($path);rmdir(dirname($path));rmdir($dir);
if(!$safe||!$useful){fwrite(STDERR,"FAIL exception log leaks synthetic secret or loses diagnostic identifiers.\n");exit(1);}
echo "PASS exception message/argument redaction; diagnostic ID/type/frames retained.\n";
