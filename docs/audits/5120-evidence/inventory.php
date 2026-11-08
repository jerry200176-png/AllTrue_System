<?php
$root = '/src/backend/app'; $out = []; $writeCandidates = []; $files = 0; $related = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $f) {
 if (!$f->isFile() || $f->getExtension() !== 'php') continue;
 $source = file_get_contents($f->getPathname()); ++$files;
 if (!preg_match('/LearningRecord|StudentClass/i', $source)) continue;
 $path = str_replace('/src/', '', $f->getPathname()); $related[] = $path;
 $tokens = token_get_all($source); $texts=[]; $lines=[]; $line=1;
 foreach ($tokens as $t) { $texts[]=is_array($t)?$t[1]:$t; $lines[]=is_array($t)?$t[2]:$line; $line+=substr_count(is_array($t)?$t[1]:$t,"\n"); }
 for ($i=0,$n=count($tokens);$i<$n;$i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0]!==T_FUNCTION) continue;
  $j=$i+1; while($j<$n && (trim($texts[$j])==='' || $texts[$j]==='&')) $j++;
  if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0]!==T_STRING) continue;
  $name=$texts[$j]; while($j<$n && $texts[$j]!=='{' && $texts[$j]!==';') $j++;
  if ($j===$n || $texts[$j]===';') continue;
  $depth=1;
  for($end=$j+1;$end<$n;$end++) {
   if($texts[$end]==='{' || (is_array($tokens[$end]) && in_array($tokens[$end][0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES]))) $depth++;
   if($texts[$end]==='}') { $depth--; if($depth===0) break; }
  }
  $body=''; for($k=$i;$k<=$end && $k<$n;$k++) $body .= is_array($tokens[$k]) && in_array($tokens[$k][0],[T_COMMENT,T_DOC_COMMENT]) ? str_repeat("\n",substr_count($texts[$k],"\n")) : $texts[$k];
  if (!preg_match('/(?:transaction\s*\(|beginTransaction\s*\(|commit\s*\(|rollBack\s*\(|lockForUpdate\s*\(|sharedLock\s*\(|for update)/i',$body)) {
   if (preg_match('/(?:->|::)(?:save|update|delete|create|insert|upsert|increment|decrement|forceDelete)\s*\(/',$body)) $writeCandidates[]=['file'=>$path,'method'=>$name,'start'=>$lines[$i],'end'=>$lines[min($end,$n-1)]];
   continue;
  }
  $tx=[]; $locks=[];
  foreach(explode("\n",$body) as $offset=>$text) {
   if(preg_match('/(?:transaction\s*\(|beginTransaction\s*\(|commit\s*\(|rollBack\s*\()/i',$text)) $tx[]=['line'=>$lines[$i]+$offset,'code'=>trim($text)];
   if(preg_match('/lockForUpdate\s*\(|sharedLock\s*\(|for update/i',$text)) $locks[]=['line'=>$lines[$i]+$offset,'code'=>trim($text)];
  }
  preg_match_all('/\b([A-Z][A-Za-z0-9_]*(?:Service|Guard|Effects|Sync))::([a-zA-Z0-9_]+)\s*\(/',$body,$m,PREG_SET_ORDER);
  $calls=array_values(array_unique(array_map(fn($v)=>$v[1].'::'.$v[2],$m)));
  $out[]=['file'=>$path,'method'=>$name,'start'=>$lines[$i],'end'=>$lines[min($end,$n-1)],'transactions'=>$tx,'explicit_locks'=>$locks,'service_calls'=>$calls];
 }
}
usort($out,fn($a,$b)=>[$a['file'],$a['start']]<=>[$b['file'],$b['start']]);
echo json_encode(['scanned_php_files'=>$files,'related_files'=>$related,'boundaries'=>$out,'write_candidates_without_local_boundary'=>$writeCandidates],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
