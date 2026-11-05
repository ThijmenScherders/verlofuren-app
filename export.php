<?php
require_once __DIR__ . '/config.php';
$user=require_login();
$start=(string)($_GET['start']??date('Y-01-01'));
$end=(string)($_GET['end']??date('Y-12-31'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end) || $end<$start) { http_response_code(400); exit('Ongeldige periode.'); }
$sql='SELECT l.start_date,l.end_date,l.hours,l.calculation_mode,l.note,u.name,u.email FROM leave_entries l JOIN users u ON u.id=l.user_id WHERE l.start_date<=? AND l.end_date>=?';
$params=[$end,$start];
if ($user['role']!=='admin') { $sql.=' AND l.user_id=?'; $params[]=$user['id']; }
$sql.=' ORDER BY l.start_date,u.name';
$stmt=db()->prepare($sql); $stmt->execute($params);
$filename='verlofuren_'.$start.'_'.$end.'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$out=fopen('php://output','w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out,['Medewerker','E-mailadres','Begindatum','Einddatum','Uren','Berekening','Opmerking'],';');
foreach($stmt as $r) fputcsv($out,[$r['name'],$r['email'],$r['start_date'],$r['end_date'],number_format((float)$r['hours'],2,',','.'),$r['calculation_mode']==='automatic'?'Automatisch':'Handmatig',$r['note']],';');
fclose($out);
