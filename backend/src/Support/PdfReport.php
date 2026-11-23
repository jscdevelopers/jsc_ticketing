<?php
declare(strict_types=1);
namespace App\Support;

/** Dependency-free, paginated text PDF. All report text is escaped as literal strings. */
final class PdfReport
{
    public static function render(array$rows):string
    {
        $lines=[];foreach($rows as$row){$lines[]=$row['reference'].' | '.$row['status'].' | '.$row['priority'];$subject=preg_replace('/\s+/u',' ',$row['subject']);foreach(array_chunk(preg_split('//u',$subject,-1,PREG_SPLIT_NO_EMPTY),90)as$characters){$lines[]='  '.implode('',$characters);}$lines[]='  Created UTC: '.$row['created_at'];$lines[]='';}
        if(!$lines)$lines[]='No authorized tickets matched the selected filters.';
        $pages=array_chunk($lines,44);$objects=[1=>'',2=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];$pageIds=[];
        foreach($pages as$i=>$page){$pageId=count($objects)+1;$contentId=$pageId+1;$pageIds[]=$pageId;$stream="BT /F1 14 Tf 40 800 Td (JSC Ticketing - authorized report) Tj /F1 9 Tf 0 -20 Td (".count($rows).' records | Page '.($i+1).' of '.count($pages).") Tj 0 -22 Td 16 TL\n";
            foreach($page as$line){$stream.='('.self::escape(self::text($line)).") Tj T*\n";}$stream.='ET';
            $objects[$pageId]='<< /Type /Page /Parent 1 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 2 0 R >> >> /Contents '.$contentId.' 0 R >>';$objects[$contentId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $objects[1]='<< /Type /Pages /Count '.count($pageIds).' /Kids ['.implode(' ',array_map(static fn($id)=>$id.' 0 R',$pageIds)).'] >>';$catalog=count($objects)+1;$objects[$catalog]='<< /Type /Catalog /Pages 1 0 R >>';
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as$id=>$body){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$body."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";foreach(array_slice($offsets,1)as$offset){$pdf.=sprintf('%010d 00000 n ',$offset)."\n";}
        return$pdf.'trailer << /Size '.(count($objects)+1).' /Root '.$catalog." 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
    private static function text(string$value):string{$value=preg_replace('/[\x00-\x1F\x7F]/',' ',$value);$converted=@iconv('UTF-8','Windows-1252//TRANSLIT',$value);return$converted===false?preg_replace('/[^\x20-\x7E]/','?',$value):$converted;}
    private static function escape(string$value):string{return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);}
}
