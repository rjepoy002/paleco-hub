<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
[$script,$email,$name,$role]=array_pad($argv,4,''); $email=strtolower(trim($email));
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !$name || !in_array($role,['Administrator','Publisher'],true)) { fwrite(STDERR,"Usage: php bin/approve-user.php email@example.com \"Full Name\" Administrator|Publisher\n"); exit(1); }
try { App\Models\DB::query('INSERT INTO users (email,name,role_id) VALUES (?,?,?)',[$email,$name,$role==='Administrator'?1:2]); App\Models\DB::audit(null,'cli.account_approved'); echo "Account approved. Sign in with the matching verified Google identity.\n"; } catch(Throwable $ex) { fwrite(STDERR,"Account could not be created; check database configuration and duplicate email addresses.\n"); exit(1); }
