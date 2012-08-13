<?php

final class MustacheTokeniser
{
  public static function tokenise( $template )
  {
    $tokeniser = new self;
    $tokeniser->scanner = new StringScanner( $template );
    $tokeniser->tokens  = new MustacheTokenStream;
    $tokeniser->process();

    assert( $tokeniser->tokens->originalText() === $template );

    return $tokeniser->tokens;
  }

  private $scanner;
  private $tokens;

  private $openTag  = '{{';
  private $closeTag = '}}';

  private function __construct() {}

  private function process()
  {
    while ( $this->hasTextRemaining() )
      $this->processOne();
  }

  private function processOne()
  {
    $this->skipToNextTagOrEof();

    $isStartOfLine = $this->isStartOfLine();
    $indent        = $this->scanText( $this->indentRegex() );

    if ( $this->textMatches( $this->openTagRegex() ) )
      $this->handleTagFound( $isStartOfLine, $indent );
    else
      $this->addText( $indent . $this->scanText( '.*' ) );
  }

  private function skipToNextTagOrEof()
  {
    $newLine = $this->newLineRegex();
    $indent  = $this->indentRegex();
    $openTag = $this->openTagRegex();

    $this->addText( $this->scanText( ".*?(?<=$newLine|)(?=$indent$openTag|$)" ) );
  }

  private function handleTagFound( $isStartOfLine, $indent )
  {
    $token = $this->scanSingleTag();
    $token = $this->handleStandaloneTag( $isStartOfLine, $indent, $token );

    $this->handleChangeDelimiters( $token );
    $this->tokens->addTag( $token );
  }

  private function scanSingleTag()
  {
    $token = new MustacheTokenTag;
    $token->openTag       = $this->scanText( $this->openTagRegex() );
    $token->type          = $this->scanText( $this->tagTypeRegex() );
    $token->paddingBefore = $this->scanText( ' *' );
    $token->content       = $this->scanText( $this->tagContentRegex( $token->type ) );
    $token->paddingAfter  = $this->scanText( ' *' );
    $token->closeType     = $this->scanText( $this->closeTypeRegex( $token->type ) );
    $token->closeTag      = $this->scanText( $this->closeTagRegex() );

    return $token;
  }

  private function handleStandaloneTag( $isStartOfLine, $indent, MustacheTokenTag $token )
  {
    if ( $this->isStandaloneTag( $isStartOfLine, $token->type ) )
      $token = $this->convertToStandaloneTag( $token, $indent );
    else
      $this->addText( $indent );

    return $token;
  }

  private function isStandaloneTag( $isStartOfLine, $type )
  {
    return $isStartOfLine
      && $this->typeAllowsStandalone( $type )
      && $this->textMatches( $this->eolSpaceRegex() );
  }

  private function convertToStandaloneTag( MustacheTokenTag $token, $indent )
  {
    $token = $token->toStandalone();
    $token->spaceBefore = $indent;
    $token->spaceAfter  = $this->scanText( $this->eolSpaceRegex() );

    return $token;
  }

  private function handleChangeDelimiters( MustacheTokenTag $token )
  {
    if ( $token->type == '=' )
      list( $this->openTag, $this->closeTag ) = explode( ' ', $token->content );
  }

  private function hasTextRemaining()
  {
    return $this->scanner->hasTextRemaining();
  }

  private function isStartOfLine()
  {
    return $this->textMatches( "(?<=" . $this->newLineRegex() . ")" );
  }

  private function indentRegex()
  {
    return "[\t ]*";
  }

  private function newLineRegex()
  {
    return "\r\n|\n|^";
  }

  private function openTagRegex()
  {
    return $this->escape( $this->openTag );
  }

  private function closeTagRegex()
  {
    return $this->escape( $this->closeTag );
  }

  private function eolSpaceRegex()
  {
    return $this->indentRegex() . "(" . $this->newLineRegex() . "|$)";
  }

  private function closeTypeRegex( $type )
  {
    if ( $type == '{' )
      $type = '}';

    return '(' . $this->escape( $type ) . ')?';
  }

  private function tagTypeRegex()
  {
    return "(#|\^|\/|\<|\>|\=|\!|&|\{)?";
  }

  private function tagContentRegex( $type )
  {
    if ( $this->typeHasAnyContent( $type ) )
      return ".*?(?=" . $this->closeTypeRegex( $type ) . $this->closeTagRegex() . ")";
    else
      return '(\w|[?!\/.-])*';
  }

  private function typeHasAnyContent( $type )
  {
    return $type == '!' || $type == '=';
  }

  private function typeAllowsStandalone( $type )
  {
    return $type != '&' && $type != '{' && $type != '';
  }

  private function addText( $text )
  {
    $this->tokens->addText( $text );
  }

  private function textMatches( $regex )
  {
    return $this->scanner->textMatches( $regex );
  }

  private function scanText( $regex )
  {
    return $this->scanner->scanText( $regex );
  }

  private function escape( $text )
  {
    return $this->scanner->escape( $text );
  }
}

final class StringScanner
{
  private $position = 0;
  private $string;

  public function __construct( $string )
  {
    $this->string = $string;
  }

  public function escape( $text )
  {
    return preg_quote( $text, '/' );
  }

  public function scanText( $regex )
  {
    $match = $this->matchText( $regex );

    if ( $match === null )
      throw new Exception( "Regex " . json_encode( $regex ) . " failed at offset $this->position" );

    $this->position += strlen( $match );

    return $match;
  }

  public function textMatches( $regex )
  {
    return $this->matchText( $regex ) !== null;
  }

  private function matchText( $regex )
  {
    preg_match( "/$regex/su", $this->string, $matches, PREG_OFFSET_CAPTURE, $this->position );

    if ( isset( $matches[0] ) && $matches[0][1] === $this->position )
      return $matches[0][0];
    else
      return null;
  }

  public function hasTextRemaining()
  {
    return $this->position < strlen( $this->string );
  }
}

