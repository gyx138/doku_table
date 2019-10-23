<?php

use dokuwiki\Extension\Event;
use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Parsing\Handler\Block;
use dokuwiki\Parsing\Handler\CallWriter;
use dokuwiki\Parsing\Handler\CallWriterInterface;
use dokuwiki\Parsing\Handler\Lists;
use dokuwiki\Parsing\Handler\Nest;
use dokuwiki\Parsing\Handler\Preformatted;
use dokuwiki\Parsing\Handler\Quote;
use dokuwiki\Parsing\Handler\Table;

/**
 * Class Doku_Handler
 */
class Doku_Handler {
    /** @var CallWriterInterface */
    protected $callWriter = null;

    /** @var array The current CallWriter will write directly to this list of calls, Parser reads it */
    public $calls = array();

    /** @var array internal status holders for some modes */
    protected $status = array(
        'section' => false,
        'doublequote' => 0,
    );

    /** @var bool should blocks be rewritten? FIXME seems to always be true */
    protected $rewriteBlocks = true;

    /**
     * Doku_Handler constructor.
     */
    public function __construct() {
        $this->callWriter = new CallWriter($this);
    }

    /**
     * Add a new call by passing it to the current CallWriter
     *
     * @param string $handler handler method name (see mode handlers below)
     * @param mixed $args arguments for this call
     * @param int $pos  byte position in the original source file
     */
    protected function addCall($handler, $args, $pos) {
        $call = array($handler,$args, $pos);
        $this->callWriter->writeCall($call);
    }

    /**
     * Similar to addCall, but adds a plugin call
     *
     * @param string $plugin name of the plugin
     * @param mixed $args arguments for this call
     * @param int $state a LEXER_STATE_* constant
     * @param int $pos byte position in the original source file
     * @param string $match matched syntax
     */
    protected function addPluginCall($plugin, $args, $state, $pos, $match) {
        $call = array('plugin',array($plugin, $args, $state, $match), $pos);
        $this->callWriter->writeCall($call);
    }

    /**
     * The following methods define the handlers for the different Syntax modes
     *
     * The handlers are called from dokuwiki\Parsing\Lexer\Lexer\invokeParser()
     *
     * @todo it might make sense to move these into their own class or merge them with the
     *       ParserMode classes some time.
     */
    // region mode handlers

    /**
     * @param string $match matched syntax
     * @param int $state a LEXER_STATE_* constant
     * @param int $pos byte position in the original source file
     * @return bool mode handled?
     */
    public function table($match, $state, $pos) {
        switch ( $state ) {

            case DOKU_LEXER_ENTER:

                $this->callWriter = new Table($this->callWriter);

                $this->addCall('table_start', array($pos + 1), $pos);
                if ( trim($match) == '^' ) {
                    $this->addCall('tableheader', array(), $pos);
                } else {
                    $this->addCall('tablecell', array(), $pos);
                }
            break;

            case DOKU_LEXER_EXIT:
                $this->addCall('table_end', array($pos), $pos);
                /** @var Table $reWriter */
                $reWriter = $this->callWriter;
                $this->callWriter = $reWriter->process();
            break;

            case DOKU_LEXER_UNMATCHED:
                if ( trim($match) != '' ) {
                    $this->addCall('cdata', array($match), $pos);
                }
            break;

            case DOKU_LEXER_MATCHED:
                if ( $match == ' ' ){
                    $this->addCall('cdata', array($match), $pos);
                } else if ( preg_match('/:::/',$match) ) {
                    $this->addCall('rowspan', array($match), $pos);
                } else if ( preg_match('/\t+/',$match) ) {
                    $this->addCall('table_align', array($match), $pos);
                } else if ( preg_match('/ {2,}/',$match) ) {
                    $this->addCall('table_align', array($match), $pos);
                } else if ( $match == "\n|" ) {
                    $this->addCall('table_row', array(), $pos);
                    $this->addCall('tablecell', array(), $pos);
                } else if ( $match == "\n^" ) {
                    $this->addCall('table_row', array(), $pos);
                    $this->addCall('tableheader', array(), $pos);
                } else if ( $match == '|' ) {
                    $this->addCall('tablecell', array(), $pos);
                } else if ( $match == '^' ) {
                    $this->addCall('tableheader', array(), $pos);
                }
            break;
        }
        return true;
    }

    // endregion modes
}

//------------------------------------------------------------------------
function Doku_Handler_Parse_Media($match) {

    // Strip the opening and closing markup
    $link = preg_replace(array('/^\{\{/','/\}\}$/u'),'',$match);

    // Split title from URL
    $link = explode('|',$link,2);

    // Check alignment
    $ralign = (bool)preg_match('/^ /',$link[0]);
    $lalign = (bool)preg_match('/ $/',$link[0]);

    // Logic = what's that ;)...
    if ( $lalign & $ralign ) {
        $align = 'center';
    } else if ( $ralign ) {
        $align = 'right';
    } else if ( $lalign ) {
        $align = 'left';
    } else {
        $align = null;
    }

    // The title...
    if ( !isset($link[1]) ) {
        $link[1] = null;
    }

    //remove aligning spaces
    $link[0] = trim($link[0]);

    //split into src and parameters (using the very last questionmark)
    $pos = strrpos($link[0], '?');
    if($pos !== false){
        $src   = substr($link[0],0,$pos);
        $param = substr($link[0],$pos+1);
    }else{
        $src   = $link[0];
        $param = '';
    }

    //parse width and height
    if(preg_match('#(\d+)(x(\d+))?#i',$param,$size)){
        !empty($size[1]) ? $w = $size[1] : $w = null;
        !empty($size[3]) ? $h = $size[3] : $h = null;
    } else {
        $w = null;
        $h = null;
    }

    //get linking command
    if(preg_match('/nolink/i',$param)){
        $linking = 'nolink';
    }else if(preg_match('/direct/i',$param)){
        $linking = 'direct';
    }else if(preg_match('/linkonly/i',$param)){
        $linking = 'linkonly';
    }else{
        $linking = 'details';
    }

    //get caching command
    if (preg_match('/(nocache|recache)/i',$param,$cachemode)){
        $cache = $cachemode[1];
    }else{
        $cache = 'cache';
    }

    // Check whether this is a local or remote image or interwiki
    if (media_isexternal($src) || link_isinterwiki($src)){
        $call = 'externalmedia';
    } else {
        $call = 'internalmedia';
    }

    $params = array(
        'type'=>$call,
        'src'=>$src,
        'title'=>$link[1],
        'align'=>$align,
        'width'=>$w,
        'height'=>$h,
        'cache'=>$cache,
        'linking'=>$linking,
    );

    return $params;
}

