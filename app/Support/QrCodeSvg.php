<?php

namespace App\Support;

use InvalidArgumentException;

final class QrCodeSvg
{
    // Version 5-L byte mode: 37x37 modules, 108 data codewords and
    // 26 error-correction codewords. This raises the payload ceiling to
    // 106 bytes while retaining the existing local SVG encoder and styling.
    private const VERSION = 5;
    private const SIZE = 37;
    private const DATA_CODEWORDS = 108;
    private const ECC_CODEWORDS = 26;
    private const MAX_BYTES = 106;

    public static function dataUri(string $text): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text));
    }

    public static function svg(string $text): string
    {
        $modules=self::encode($text);
        $quiet=4;
        $size=self::SIZE;
        $view=$size+($quiet*2);
        $path=[];

        for($y=0;$y<$size;$y++){
            for($x=0;$x<$size;$x++){
                if($modules[$y][$x]){
                    $path[]='M'.($x+$quiet).' '.($y+$quiet).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$view.' '.$view.'" shape-rendering="crispEdges">'
            .'<rect width="100%" height="100%" fill="#fff"/>'
            .'<path d="'.implode('',$path).'" fill="#000"/>'
            .'</svg>';
    }

    /** @return array<int,array<int,bool>> */
    private static function encode(string $text): array
    {
        $bytes=array_values(unpack('C*',$text) ?: []);
        if(count($bytes)>self::MAX_BYTES){
            throw new InvalidArgumentException('Public voucher URL is too long for the built-in QR encoder.');
        }

        $bits=[];
        self::appendBits($bits,0x4,4); // byte mode
        self::appendBits($bits,count($bytes),8);

        foreach($bytes as $byte){
            self::appendBits($bits,$byte,8);
        }

        $capacity=self::DATA_CODEWORDS*8;
        $terminator=min(4,$capacity-count($bits));
        for($i=0;$i<$terminator;$i++) $bits[]=0;
        while(count($bits)%8!==0) $bits[]=0;

        $data=[];
        for($i=0;$i<count($bits);$i+=8){
            $value=0;
            for($j=0;$j<8;$j++) $value=($value<<1)|$bits[$i+$j];
            $data[]=$value;
        }

        $pads=[0xEC,0x11];
        $p=0;
        while(count($data)<self::DATA_CODEWORDS){
            $data[]=$pads[$p%2];
            $p++;
        }

        $ecc=self::reedSolomonRemainder($data,self::ECC_CODEWORDS);
        $all=array_merge($data,$ecc);

        $dataBits=[];
        foreach($all as $byte){
            self::appendBits($dataBits,$byte,8);
        }

        $size=self::SIZE;
        $modules=array_fill(0,$size,array_fill(0,$size,false));
        $function=array_fill(0,$size,array_fill(0,$size,false));

        $set=function(int $x,int $y,bool $dark) use (&$modules,&$function,$size): void {
            if($x<0||$y<0||$x>=$size||$y>=$size) return;
            $modules[$y][$x]=$dark;
            $function[$y][$x]=true;
        };

        // Timing patterns first. Finder patterns overwrite the overlapping ends.
        for($i=0;$i<$size;$i++){
            $set(6,$i,$i%2===0);
            $set($i,6,$i%2===0);
        }

        self::drawFinder($set,3,3);
        self::drawFinder($set,$size-4,3);
        self::drawFinder($set,3,$size-4);

        // Version 5 has alignment centers [6,30]; only 30,30 does not overlap a finder.
        self::drawAlignment($set,30,30);

        // Format information for error correction L and mask 0.
        $format=self::formatBits(0);
        for($i=0;$i<=5;$i++) $set(8,$i,(($format>>$i)&1)!==0);
        $set(8,7,(($format>>6)&1)!==0);
        $set(8,8,(($format>>7)&1)!==0);
        $set(7,8,(($format>>8)&1)!==0);
        for($i=9;$i<15;$i++) $set(14-$i,8,(($format>>$i)&1)!==0);

        for($i=0;$i<8;$i++) $set($size-1-$i,8,(($format>>$i)&1)!==0);
        for($i=8;$i<15;$i++) $set(8,$size-15+$i,(($format>>$i)&1)!==0);
        $set(8,$size-8,true); // dark module

        // Data placement.
        $bitIndex=0;
        for($right=$size-1;$right>=1;$right-=2){
            if($right===6) $right=5;
            for($vert=0;$vert<$size;$vert++){
                $upward=((($right+1)&2)===0);
                $y=$upward ? $size-1-$vert : $vert;
                for($j=0;$j<2;$j++){
                    $x=$right-$j;
                    if(!$function[$y][$x]){
                        $modules[$y][$x]=$bitIndex<count($dataBits) ? ($dataBits[$bitIndex]===1) : false;
                        $bitIndex++;
                    }
                }
            }
        }

        // Mask pattern 0: (x+y) mod 2 == 0.
        for($y=0;$y<$size;$y++){
            for($x=0;$x<$size;$x++){
                if(!$function[$y][$x] && (($x+$y)%2===0)){
                    $modules[$y][$x]=!$modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    private static function drawFinder(callable $set,int $cx,int $cy): void
    {
        for($dy=-4;$dy<=4;$dy++){
            for($dx=-4;$dx<=4;$dx++){
                $dist=max(abs($dx),abs($dy));
                $set($cx+$dx,$cy+$dy,$dist!==2 && $dist!==4);
            }
        }
    }

    private static function drawAlignment(callable $set,int $cx,int $cy): void
    {
        for($dy=-2;$dy<=2;$dy++){
            for($dx=-2;$dx<=2;$dx++){
                $set($cx+$dx,$cy+$dy,max(abs($dx),abs($dy))!==1);
            }
        }
    }

    private static function formatBits(int $mask): int
    {
        $data=(1<<3)|$mask; // ECL L = binary 01
        $rem=$data;
        for($i=0;$i<10;$i++){
            $rem=($rem<<1)^((($rem>>9)&1)!==0 ? 0x537 : 0);
        }
        return (($data<<10)|$rem)^0x5412;
    }

    /** @param array<int,int> $data @return array<int,int> */
    private static function reedSolomonRemainder(array $data,int $degree): array
    {
        $generator=[1];
        $root=1;

        for($i=0;$i<$degree;$i++){
            $next=array_fill(0,count($generator)+1,0);
            for($j=0;$j<count($generator);$j++){
                $next[$j]^=$generator[$j];
                $next[$j+1]^=self::gfMultiply($generator[$j],$root);
            }
            $generator=$next;
            $root=self::gfMultiply($root,2);
        }

        $result=array_fill(0,$degree,0);

        foreach($data as $byte){
            $factor=$byte^$result[0];
            for($i=0;$i<$degree-1;$i++) $result[$i]=$result[$i+1];
            $result[$degree-1]=0;

            for($i=0;$i<$degree;$i++){
                $result[$i]^=self::gfMultiply($generator[$i+1],$factor);
            }
        }

        return $result;
    }

    private static function gfMultiply(int $x,int $y): int
    {
        $z=0;
        for($i=0;$i<8;$i++){
            if(($y&1)!==0) $z^=$x;
            $carry=($x&0x80)!==0;
            $x=($x<<1)&0xFF;
            if($carry) $x^=0x1D;
            $y>>=1;
        }
        return $z&0xFF;
    }

    /** @param array<int,int> $bits */
    private static function appendBits(array &$bits,int $value,int $length): void
    {
        for($i=$length-1;$i>=0;$i--){
            $bits[]=($value>>$i)&1;
        }
    }
}
