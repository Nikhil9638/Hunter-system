<?php
/** Database-independent parser/password/router checks; not HTTP authorization evidence. */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Hunter\Http\ApiError;
use Hunter\Http\Request;
use Hunter\Http\Router;
use Hunter\Support\PasswordPolicy;
$checks=0;
function ok(bool $condition):void{global $checks;if(!$condition)throw new RuntimeException('Local security assertion failed');$checks++;}
function parseBody(string $body):array{$r=(new ReflectionClass(Request::class))->newInstanceWithoutConstructor();(new ReflectionProperty(Request::class,'body'))->setValue($r,$body);return $r->jsonObject(['name']);}
ok(parseBody('{}')===[]);
ok(parseBody('{"name":"x\u0027 OR 1=1 --"}')['name']==="x' OR 1=1 --");
ok(parseBody('{"name":"<script>alert(1)</script>"}')['name']==='<script>alert(1)</script>');
foreach(['[]','[1]','null','true','"text"','{bad','{"role":"admin"}'] as $bad){try{parseBody($bad);throw new RuntimeException('Invalid object accepted');}catch(ApiError $expected){ok(true);}}
ok(PasswordPolicy::problem('short')==='too_short');ok(PasswordPolicy::problem(str_repeat('x',257))==='too_long');
ok(PasswordPolicy::problem('A long local sample phrase')===null);
$normalized=PasswordPolicy::normalizeNew('A long local sample phrase');$hash=PasswordPolicy::hash($normalized);
ok(PasswordPolicy::verify($normalized,$hash));ok(!PasswordPolicy::verify('Different local sample phrase',$hash));
$router=new Router();$router->add('GET','/students/{id}',static fn()=>null,['auth'=>'admin']);
[$handler,$ids,$options]=$router->match('GET','/students/12');ok($ids===[12]&&$options['auth']==='admin');
foreach(['/students/0','/students/-1','/students/1 OR 1=1','/students/12/extra'] as $path){try{$router->match('GET',$path);throw new RuntimeException('Unsafe path accepted');}catch(ApiError $expected){ok(true);}}
echo "$checks database-independent security checks passed.\n";
