<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
try{
    $db=App\Config\Database::connection();$tables=$db->query("SELECT tablename FROM pg_tables WHERE schemaname=current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);$database=[];
    foreach($tables as$table){
        if(!preg_match('/^[a-z_]+$/D',$table)){throw new RuntimeException('Unexpected table.');}
        $digest=hash_init('sha256');$count=0;
        $rows=$db->query('SELECT md5(row_to_json(r)::text) AS digest FROM "'.$table.'" r ORDER BY 1');
        while($row=$rows->fetch()){$count++;hash_update($digest,$row['digest']."\n");}
        $database[$table]=['rows'=>$count,'digest'=>hash_final($digest)];
    }
    $sequences=[];
    foreach($db->query("SELECT sequencename FROM pg_sequences WHERE schemaname=current_schema() ORDER BY sequencename")->fetchAll(PDO::FETCH_COLUMN)as$sequence){
        if(!preg_match('/^[a-z_]+$/D',$sequence)){throw new RuntimeException('Unexpected sequence.');}
        $sequences[$sequence]=$db->query('SELECT last_value,is_called FROM "'.$sequence.'"')->fetch();
    }
    $root=getenv('PRIVATE_STORAGE_PATH')?:'/var/lib/ticketing/private';if(!is_dir($root)){throw new RuntimeException('Private storage unavailable.');}
    $files=[];foreach(new DirectoryIterator($root)as$file){if($file->isDot()){continue;}
        if(!$file->isFile()||$file->isLink()||!preg_match('/^[a-f0-9]{48}$/D',$file->getFilename())){throw new RuntimeException('Unexpected private storage entry.');}
        $files[$file->getFilename()]=['size'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getPathname())];
    }ksort($files);
    echo json_encode(['database'=>$database,'sequences'=>$sequences,'files'=>(object)$files],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable$error){fwrite(STDERR,'Recovery manifest failed ('.$error::class.').'.PHP_EOL);exit(1);}
