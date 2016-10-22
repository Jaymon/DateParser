<?php
/**
 *  parse_date php class
 *  
 *  you have 2 options when using this class, you can try and find a date in arbitrary
 *  block of text or you can relax the rules a little by searching in a given field.
 *  
 *  to add rules, take a look at parse_date::getRules() and add rules to your hearts content, see
 *  notes on that function for how to do it
 *  
 *  NOTE: parse_date is not a magical crystal ball that will accurately
 *    guess every single date in every single format and do it right every time. what it does do 
 *    is try to correctly determine the date but will probably be wrong sometimes. 
 *    The best way to guarantee accuracy is to encourage the user to give as much 
 *    date detail as possible, like giving am or pm on the time, a 4-digit year, etc.
 *  
 *  @version 0.7
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 8-13-08
 *  @project parse_date
 *
 *  @todo: 
 *    - "every other" for recurring dates that alternate days or weeks
 *    - "the first [day] of the month" (eg, "the first thursday of every month") and "the end of the month"
 *    - it only catches one date on input like: "this is due november 21st at 7pm and december 5" it should catch both 
 *    
 *  @ideas:
 *    - to do the date through date, just have match, when it has found a date, array_slice() 
 *      the token list if date doesn't have a time and isn't multi-day and call match again, this could be done automatically
 *      update 10-27-09: while I like this idea, I went ahead and created rules that do the through stuff.  
 *   
 ******************************************************************************/
/* changelog...
8-13-08 - broke this class out from deadline to separate parsing from storing in the db
11-14-08 - belt-tightening, the postfix (month index) of findImpliedDate() has to be there now, since it was matching
  "the first item" etc. too easily.
1-6-09 - fixed some bugs with getDateMap() where it wasn't getting the right start day, and also added support for
  end|middle|first in findMonth()
4-6-09 - did some bug fixing where the input was being parsed a little wrong. I'm thinking the findNamedMonth() function
  is eventually going to need a complete re-write with tons tighter rules (eg, only allow month day, year type dates)
  because I think the current incarnation is too leniant
7-21-09 - got support for 'for tonight' in findDay()
9-8-09 - changed \b to \s in the first part of the regex in day(), to keep urls from being found
9-15-09 - complete refactor and re-write for plancast, this class is now meaner and leaner than ever
  and the new rules based parser should make adding more dates in the future a snap
10-11-09 - added support for changing NOW so the class could process things that took place in the past
  as if they were taking place now. Fixed a typo, I had DFAULT instead of DEFAULT 
10-28-09 - added recurring support, rules are now done with the parse_date_rule class instead of an array(), fixed a whole
  bunch of bugs and re-factored a lot of code to fix them
10-29-09 - keywords are now handled by the keywords and keyword classes, some more date format rules added
11-3-09 - word offsets were all wrong so fixed that, fixed date_info_map::out() having problems correctly rendering 
  "all" duration type events (eg, all day, all month). Added restrictions on findInText() so that match() no longer
  ignored word offsets in the text state (still does for field state). This allows the parser to be tons more accurate
  for "full text" date parsing
*/
 
 
class parse_date {

  /**#@+
   *  these are the DATE DESCRIPTION constants
   *     
   *  these constants are used for creating the rules, see {@link getRules()} to see
   *  how these are used       
   */
  /**
   *  any number
   */
  const NUM = 1;
  /**
   *  1-31
   */     
  const DAY_NUM = 2;
  /**
   *  wednesday, monday
   */     
  const DAY_NAME = 3;
  /**
   *  year can be 2 or 4 digits, eg, 09 or 2009
   */     
  const YEAR_2 = 4;
  /**
   *  year that must be 4 digits, eg, 2009
   */     
  const YEAR_4 = 5;
  /**
   *  sept, october
   */     
  const MONTH_NAME = 6;
  /**
   *  1-12
   */     
  const MONTH_NUM = 7;
  /**
   *  something that signifies through, like -, through, thru
   */     
  const THROUGH = 8;
  /**#@+
   *  if you want to account for random words that might interrupt the date, use one
   *  of these to tell how many words the next token has to be within, 1-5 words depending
   *  on the constant used      
   */     
  const WORD_1 = 9;
  const WORD_2 = 10;
  const WORD_3 = 11;
  const WORD_4 = 12;
  const WORD_5 = 13;
  /**#@-*/
  /**
   *  8:30pm, 8pm
   */     
  const TIME = 14;
  /**
   *  8-9:30pm
   */     
  const TIME_INTERVAL = 15;
  
  /**
   *  for searching either TIME_INTERVAL or TIME in the same rule
   */     
  const TIME_ALL = 16;
  
  /**
   *  for numbers you want to treat like time, this is not included in TIME_ALL because
   *  it is too easy to get wrong, so should only be used when you know there is a high likelyhood
   *  that you are going to have numbers as times (eg, 7-9)         
   */     
  const TIME_NUM = 17;
  
  /**
   *  the slash /
   */     
  const DATE_DELIM = 18;
  
  /**
   *  this, next, for, every, etc.
   */     
  const PREFIX = 19;
  /**
   *  month, week
   */     
  const DATE_IMPLIED = 20;
  /**
   *  month
   */     
  const DATE_IMPLIED_MONTH = 21;
  
  /**#@-*/
  
  /**
   * the preferred timezone, though this can be changed by passing a timezone into the constructor
   * @var string   
   */
  const DEFAULT_TZ = 'UTC';
  
  /**#@+
   *  the possible recurrence rules that can be set
   *  @var  integer
   */        
  const RECUR_NONE = 0;
  const RECUR_DAILY = 1;
  const RECUR_WEEKLY = 2;
  const RECUR_MONTHLY = 3;
  const RECUR_YEARLY = 4;
  /**#@-*/
   
  /**
   * the timezone that will be used to find dates, can be set through {@link __construct()}
   * or just $class_instance->tz    
   */
  public $tz;
  
  /**
   *  used to tell the find* methods what the current timestamp should be
   *  
   *  set publicly with {@link setNow()}
   *      
   *  @var  integer      
   */
  private $now_timestamp = 0;
  
  /**
   *  constructor
   *
   *  instantiates a datefind object
   * 
   *  @param  string  $tz pass in a timezone {@link http://www.php.net/manual/en/timezones.php supported timezones} to override {@link DEFAULT_TZ}      
   *  @param  integer $now_timestap if you would like something other than NOW
   */              
  function __construct($tz = self::DEFAULT_TZ,$now_timestamp = null){
  
    $this->tz = empty($tz) ? self::DEFAULT_TZ : $tz;
    $this->setNow($now_timestamp);
  
  }//method
  
  /**
   *  set the now timestamp that this instance will use, by default now() is time()
   *  if you want it to be something else, then set it here
   *  
   *  REMEMBER: this timestamp will be in whatever timezone {@link $tz} is set at when 
   *    one of the find* methods are called
   *
   *  @param  integer $timestamp
   */           
  function setNow($timestamp){
    $this->now_timestamp = $timestamp;
  }//method
  
  /**
   *  gets the rules the class will use for finding dates
   *  
   *  you can set dates by using any of the date description constants and putting them in an array
   *  (eg, array(self::DAY_NUM,self::MONTH_NAME,self::WORD_3,self::TIME) to find dates matching the format
   *  (1-31) (jan|...|december) [any 0-3 words the parser can ignore) (H:MMam|...|HHpm))
   *  
   *  you need to also note that rules are cascading, so place the most robust and verbose date of whatever
   *  format you are adding at the top (eg, MONTH_NAME YEAR TIME should come before MONTH_NAME YEAR)
   *  
   *  @param  boolean $in_field you can use this to set looser rules based on the expected type of input
   *  @return array a list of the rules described above         
   */        
  private function getRules($in_field = false){
  
    $rule_list = array();
    
    /* MONTH THROUGH MONTH rules... */
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_2,self::THROUGH,
      self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4
    ); // eg, (7-8pm|8pm) on 24 september 2009 - 15 october 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_2,self::THROUGH,
      self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on 24 september 2009 - october 15, 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,
      self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4
    ); // eg, (7-8pm|8pm) on 24 september - 15 october 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,
      self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on 24 september - october 15, 2009
    
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, 24 september 2009 - 15 october 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, 24 september 2009 - october 15, 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, 24 september - 15 october 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, 24 september - october 15, 2009 at (7-8pm|8pm)
    
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_2,self::THROUGH,
      self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4
    ); // eg, (7-8pm|8pm) on september 24, 2009 - 15 october 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_2,self::THROUGH,
      self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on september 24, 2009 - october 15, 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,
      self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4
    ); // eg, (7-8pm|8pm) on september 24 - 15 october 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,
      self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on september 24 - october 15, 2009
    
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, september 24, 2009 - 15 october 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, september 24, 2009 - october 15, 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, september 24 - 15 october 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, september 24 - october 15, 2009 at (7-8pm|8pm)
    
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4); // eg, 24 september 2009 - 15 october 2009
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4); // eg, 24 september 2009 - october 15, 2009
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4); // eg, 24 september - 15 october 2009
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4); // eg, 24 september - october 15, 2009
    
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4); // eg, september 24, 2009 - 15 october 2009
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4); // eg, september 24, 2009 - october 15, 2009
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4); // eg, september 24 - 15 october 2009
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM,self::YEAR_4); // eg, september 24 - october 15, 2009
    
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME); // eg, every 24 september - 15 october
    $rule->recur(self::RECUR_YEARLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME); // eg, 24 september - 15 october
    
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM); // eg, every 24 september - october 15
    $rule->recur(self::RECUR_YEARLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM); // eg, 24 september - october 15
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME); // eg, every september 24 - 15 october
    $rule->recur(self::RECUR_YEARLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::DAY_NUM,self::MONTH_NAME); // eg, september 24 - 15 october
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM); // eg, every september 24 - october 15
    $rule->recur(self::RECUR_YEARLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_2,self::THROUGH,self::WORD_2,self::MONTH_NAME,self::DAY_NUM); // eg, september 24 - october 15
    
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::YEAR_4,self::THROUGH,self::MONTH_NAME,self::YEAR_4); // eg, september 2009 - october 2009
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::THROUGH,self::MONTH_NAME,self::YEAR_4); // eg, september - october 2009
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::THROUGH,self::MONTH_NAME); // eg, every september - october
    $rule->recur(self::RECUR_YEARLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::THROUGH,self::MONTH_NAME); // eg, september - october
    
    /* DAY MONTH_NAME YEAR TIME rules... */
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4
    ); // eg, (7-8pm|8pm) on 24-26 september 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::MONTH_NAME,self::YEAR_4
    ); // eg, (7-8pm|8pm) on 24 september 2009
    
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, 24-26 september 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, 24 september 2009 at (7-8pm|8pm)
    
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME,self::WORD_3,self::TIME_ALL); // eg, every 24-26 september at (7-8pm|8pm)
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME
    ); // eg, (7-8pm|8pm) on 24-26 september
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME,self::WORD_3,self::TIME_ALL); // eg, 24-26 september at (7-8pm|8pm)
    
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NUM,self::MONTH_NAME,self::WORD_3,self::TIME_ALL); // eg, every 24 september at (7-8pm|8pm)
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::DAY_NUM,self::MONTH_NAME
    ); // eg, (7-8pm|8pm) on 24 september
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::WORD_3,self::TIME_ALL); // eg, 24 september at (7-8pm|8pm)
    
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME,self::YEAR_4); // eg, 24-26 september 2009
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME,self::YEAR_4); // eg, 24 september 2009
    
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME); // eg, every 24-26 september
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::MONTH_NAME); // eg, 24-26 september
    
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NUM,self::MONTH_NAME); // eg, every 24 september
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::DAY_NUM,self::MONTH_NAME); // eg, 24 september
    
    /* MONTH_NAME DAY YEAR TIME rules... */
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::THROUGH,
      self::DAY_NUM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on september 24-26, 2009
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on september 24, 2009
    
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, september 24-26, 2009 at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, september 24, 2009 at (7-8pm|8pm)
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, every september 24-26 at (7-8pm|8pm)
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM,self::THROUGH,
      self::DAY_NUM
    ); // eg, (7-8pm|8pm) on september 24-26
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, september 24-26 at (7-8pm|8pm)
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, every september 24 at (7-8pm|8pm)
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NAME,self::DAY_NUM
    ); // eg, (7-8pm|8pm) on september 24
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, september 24 at (7-8pm|8pm)
    
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::YEAR_4); // eg, september 24-26 2009
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::YEAR_4); // eg, september 24 2009
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::DAY_NUM,self::THROUGH,self::DAY_NUM); // eg, every september 24-26
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM,self::THROUGH,self::DAY_NUM); // eg, september 24-26
    
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME,self::DAY_NUM); // eg, every september 24
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::DAY_NUM); // eg, september 24
      
    /* Misc MONTH_NAME rules... */
    $rule_list[] = new parse_date_rule(self::MONTH_NAME,self::YEAR_4); // eg september 2009
    $rule = new parse_date_rule(self::PREFIX,self::MONTH_NAME); // eg this september
    $rule->recur(self::RECUR_YEARLY);
    $rule_list[] = $rule;
    if($in_field){
      $rule_list[] = new parse_date_rule(self::MONTH_NAME); // eg september
    }//if
    
    /* YEAR MONTH DAY - YEAR MONTH DAY rules... */
    $rule_list[] = new parse_date_rule(
      self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
      self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,
      self::WORD_3,self::TIME_INTERVAL
    ); // eg, YYYY/MM/DD - YYYY/MM/DD at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
      self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM  
    ); // eg, (7-8pm|8pm) on YYYY/MM/DD - YYYY/MM/DD
    $rule_list[] = new parse_date_rule(
      self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
      self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM
    ); // eg, YYYY/MM/DD - YYYY/MM/DD
    
    /* YEAR MONTH DAY rules... */
    $rule_list[] = new parse_date_rule(self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, YYYY/MM/DD at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::TIME_ALL,self::WORD_3,self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM); // eg, (7-8pm|8pm) on YYYY/MM/DD
    $rule_list[] = new parse_date_rule(self::YEAR_4,self::DATE_DELIM,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM); // eg, YYYY/MM/DD
    
    /* MONTH DAY YEAR rules... */
    $rule_list[] = new parse_date_rule(
      self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4,self::THROUGH,
      self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4
      ,self::WORD_3,self::TIME_ALL
    ); // eg, MM/DD/YYYY - MM/DD/YYYY at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,
      self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4,self::THROUGH,
      self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4
    ); // eg, (7-8pm|8pm) on MM/DD/YYYY - MM/DD/YYYY
    $rule_list[] = new parse_date_rule(
      self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4,
      self::THROUGH,
      self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4
    ); // eg, MM/DD/YYYY - MM/DD/YYYY
    
    /* MONTH DAY YEAR rules... */
    $rule_list[] = new parse_date_rule(self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4,self::WORD_3,self::TIME_ALL); // eg, MM/DD/YYYY at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(self::TIME_ALL,self::WORD_3,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4); // eg, (7-8pm|8pm) on MM/DD/YYYY
    $rule_list[] = new parse_date_rule(self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_4); // eg, MM/DD/YYYY
    
    /* MONTH DAY [YEAR 2] rules... */
    if($in_field){
    
      $rule_list[] = new parse_date_rule(
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2,
        self::WORD_3,self::TIME_ALL
      ); // eg, MM/DD/YY - MM/DD/YY at (7-8pm|8pm)
      $rule_list[] = new parse_date_rule(
        self::TIME_ALL,self::WORD_3,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2
      ); // eg, (7-8pm|8pm) on MM/DD/YY - MM/DD/YYYY
      $rule_list[] = new parse_date_rule(
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2
      ); // eg, MM/DD/YY - MM/DD/YYYY
      
      $rule_list[] = new parse_date_rule(self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2,self::WORD_3,self::TIME_ALL); // eg, MM/DD/YY at (7-8pm|8pm)
      $rule_list[] = new parse_date_rule(self::TIME_ALL,self::WORD_3,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2); // eg, (7-8pm|8pm) on MM/DD/YY
      $rule_list[] = new parse_date_rule(self::TIME,self::WORD_3,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2); // eg, 8pm on MM/DD/YY
      $rule_list[] = new parse_date_rule(self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::DATE_DELIM,self::YEAR_2); // eg, MM/DD/YY
      
      /* MONTH DAY rules... */
      $rule_list[] = new parse_date_rule(
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,
        self::WORD_3,self::TIME_ALL
      ); // eg, MM/DD - MM/DD at (7-8pm|8pm)
      $rule_list[] = new parse_date_rule(
        self::TIME_ALL,self::WORD_3,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM
      ); // eg, (7-8pm|8pm) on MM/DD - MM/DD
      $rule_list[] = new parse_date_rule(
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM
      ); // eg, MM/DD - MM/DD
      
      $rule = new parse_date_rule(self::PREFIX,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, every MM/DD at (7-8pm|8pm)
      $rule->recur(self::RECUR_MONTHLY);
      $rule_list[] = $rule;
      $rule_list[] = new parse_date_rule(self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::WORD_3,self::TIME_ALL); // eg, MM/DD at (7-8pm|8pm)
      
      $rule = new parse_date_rule(self::PREFIX,self::TIME_ALL,self::WORD_3,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM); // eg, every (7-8pm|8pm) on MM/DD
      $rule->recur(self::RECUR_MONTHLY);
      $rule_list[] = $rule;
      $rule_list[] = new parse_date_rule(self::TIME_ALL,self::WORD_3,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM); // eg, (7-8pm|8pm) on MM/DD
      
      $rule = new parse_date_rule(self::PREFIX,self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM); // eg, every MM/DD
      $rule->recur(self::RECUR_MONTHLY);
      $rule_list[] = $rule;
      $rule_list[] = new parse_date_rule(self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM); // eg, MM/DD
    }//if
    
    /*  DAY_NAME DAY_NUM TIME rules... */
    $rule_list[] = new parse_date_rule(
      self::TIME_ALL,self::WORD_3,self::DAY_NAME,self::WORD_2,self::DAY_NUM
    ); // eg, (7-8pm|8pm) on Monday the 15th
    $rule_list[] = new parse_date_rule(
      self::DAY_NAME,self::WORD_2,self::DAY_NUM,self::WORD_3,self::TIME_ALL
    ); // eg, Monday the 15th at (7-8pm|8pm)
    $rule_list[] = new parse_date_rule(
      self::DAY_NAME,self::WORD_2,self::DAY_NUM
    ); // eg, Monday the 15th
    
    /* PREFIX DAY_NAME TIME rules... */
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME,self::WORD_3,self::TIME_ALL); // eg, next tuesday-friday at (7-8pm|8pm)
    $rule->recur(self::RECUR_WEEKLY);
    $rule_list[] = $rule;
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::WORD_3,self::TIME_ALL); // eg, next tuesday at (7-8pm|8pm)
    $rule->recur(self::RECUR_WEEKLY);
    $rule_list[] = $rule;
    
    if($in_field){
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME,self::WORD_3,self::TIME_ALL); // eg, tuesday - friday at (7-8pm|8pm)
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::WORD_3,self::TIME_ALL); // eg, tuesday at (7-8pm|8pm)
      
      $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME,self::WORD_3,self::TIME_NUM,self::THROUGH,self::TIME_NUM); // eg, every tuesday - friday at 7-8
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::WORD_3,self::TIME_NUM,self::THROUGH,self::TIME_NUM); // eg, every tuesday at 7-8
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME,self::WORD_3,self::TIME_NUM,self::THROUGH,self::TIME_NUM); // eg, tuesday - friday at 7-8
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::WORD_3,self::TIME_NUM,self::THROUGH,self::TIME_NUM); // eg, tuesday at 7-8
      
      $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME,self::WORD_3,self::TIME_NUM); // eg, every tuesday-friday at 7
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::WORD_3,self::TIME_NUM); // eg, every tuesday at 7
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME,self::WORD_3,self::TIME_NUM); // eg, tuesday-friday at 7
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::WORD_3,self::TIME_NUM); // eg, tuesday at 7
    }//if
    
    /* TIME PREFIX DAY_NAME rules... */
    $rule = new parse_date_rule(self::TIME_ALL,self::WORD_2,self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, (7-8pm|8pm) next tuesday
    $rule->recur(self::RECUR_WEEKLY);
    $rule_list[] = $rule;
    $rule = new parse_date_rule(self::TIME_ALL,self::WORD_2,self::PREFIX,self::DAY_NAME); // eg, (7-8pm|8pm) next tuesday
    $rule->recur(self::RECUR_WEEKLY);
    $rule_list[] = $rule;
    
    if($in_field){
      $rule_list[] = new parse_date_rule(self::TIME_ALL,self::WORD_2,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, (7-8pm|8pm) on tuesday-friday
      $rule_list[] = new parse_date_rule(self::TIME_ALL,self::WORD_2,self::DAY_NAME); // eg, (7-8pm|8pm) on tuesday
      
      $rule = new parse_date_rule(self::TIME_NUM,self::THROUGH,self::TIME_NUM,self::WORD_2,self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, 7-8 every tuesday-friday
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      $rule = new parse_date_rule(self::TIME_NUM,self::THROUGH,self::TIME_NUM,self::WORD_2,self::PREFIX,self::DAY_NAME); // eg, 7-8 every tuesday
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      
      $rule_list[] = new parse_date_rule(self::TIME_NUM,self::THROUGH,self::TIME_NUM,self::WORD_2,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, 7-8 on tuesday-friday
      $rule_list[] = new parse_date_rule(self::TIME_NUM,self::THROUGH,self::TIME_NUM,self::WORD_2,self::DAY_NAME); // eg, 7-8 on tuesday
      
      $rule = new parse_date_rule(self::TIME_NUM,self::WORD_2,self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, 7 every tuesday-friday
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      $rule = new parse_date_rule(self::TIME_NUM,self::WORD_2,self::PREFIX,self::DAY_NAME); // eg, 7 every tuesday
      $rule->recur(self::RECUR_WEEKLY);
      $rule_list[] = $rule;
      
      $rule_list[] = new parse_date_rule(self::TIME_NUM,self::WORD_2,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, 7 on tuesday-friday
      $rule_list[] = new parse_date_rule(self::TIME_NUM,self::WORD_2,self::DAY_NAME); // eg, 7 on tuesday
    }//if
    
    /* PREFIX DAY_NAME rules... */
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, next tuesday-friday
    $rule->recur(self::RECUR_WEEKLY);
    $rule_list[] = $rule;
    $rule = new parse_date_rule(self::PREFIX,self::DAY_NAME); // eg, next tuesday
    $rule->recur(self::RECUR_WEEKLY);
    $rule_list[] = $rule;
    
    if($in_field){
      $rule_list[] = new parse_date_rule(self::DAY_NAME,self::THROUGH,self::WORD_1,self::DAY_NAME); // eg, tuesday-friday
      $rule_list[] = new parse_date_rule(self::DAY_NAME); // eg, tuesday
    }//if
    
    $rule = new parse_date_rule(self::TIME_ALL,self::WORD_2,self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::WORD_2,self::PREFIX,self::DATE_IMPLIED_MONTH); // (7-8pm|8pm) on 24-26 of this month
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule = new parse_date_rule(self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::WORD_2,self::PREFIX,self::DATE_IMPLIED_MONTH,self::WORD_2,self::TIME_ALL); //  24-26 of this month at (7-8pm|8pm)
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    $rule = new parse_date_rule(self::TIME_ALL,self::WORD_2,self::DAY_NUM,self::WORD_2,self::PREFIX,self::DATE_IMPLIED_MONTH); // (7-8pm|8pm) on 24 of this month
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule = new parse_date_rule(self::DAY_NUM,self::WORD_2,self::PREFIX,self::DATE_IMPLIED_MONTH,self::WORD_2,self::TIME_ALL); //  24 of this month at (7-8pm|8pm)
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    $rule = new parse_date_rule(self::DAY_NUM,self::THROUGH,self::DAY_NUM,self::WORD_2,self::PREFIX,self::DATE_IMPLIED_MONTH); // 24-26 of this month
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    $rule = new parse_date_rule(self::DAY_NUM,self::WORD_2,self::PREFIX,self::DATE_IMPLIED_MONTH); //  24 of this month
    $rule->recur(self::RECUR_MONTHLY);
    $rule_list[] = $rule;
    
    if($in_field){
      $rule_list[] = new parse_date_rule(self::PREFIX,self::DATE_IMPLIED); // this week, next month
      $rule_list[] = new parse_date_rule(self::NUM,self::DATE_IMPLIED); // in 5 days
    }//if
    
    /* PREFIX TIME rules... */
    $rule = new parse_date_rule(self::PREFIX,self::TIME_ALL); // eg, for (7-8pm|8pm)
    $rule->recur(self::RECUR_DAILY);
    $rule_list[] = $rule;
    if($in_field){
      $rule_list[] = new parse_date_rule(self::TIME_ALL); // eg, (7-8pm|8pm)
    }//if
    
    if($in_field){
      $rule_list[] = new parse_date_rule(self::TIME_NUM,self::THROUGH,self::TIME_NUM); // eg, 7-8
      $rule_list[] = new parse_date_rule(self::TIME_NUM); // eg, 7
    }//if
    
    /* for debugging of a certain rule... * /
    $rule_list = array(
      new parse_date_rule(
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,self::THROUGH,
        self::MONTH_NUM,self::DATE_DELIM,self::DAY_NUM,
        self::DATE_DELIM,self::YEAR_4  
      )
    ); /*  */
  
    return $rule_list;
  
  }//method

  /**
   *  finds date strings in the given input.
   *
   *  this function finds dates in the given string input
   *
   *  @param string $input the text to be checked for dates
   *  @param integer $tz_offset the timezone offset of the user, defaults to 0
   *  @param integer $format one of either FORMAT_USA_DATE or FORMAT_WORLD_DATE
   *  @param integer $hemisphere where on earth you are, top or bottom   
   *  @return array list of all found date_maps   
   */
  function findInText($input,$tz_offset = 0,$format = null,$hemisphere = null){
    return $this->find(false,$input,$tz_offset,$format,$hemisphere);
  }//method
  
  /**
   *  finds date strings in the given field.
   *
   *  this function finds dates in the given field, this is different than {@link findInText()}
   *  because the rules will be looser since the entire input is technically the date so it
   *  doesn't have to be as strict      
   *
   *  @param string $input the text to be checked for dates
   *  @param integer $tz_offset the timezone offset of the user, defaults to 0
   *  @param integer $format one of either FORMAT_USA_DATE or FORMAT_WORLD_DATE
   *  @param integer $hemisphere where on earth you are, top or bottom   
   *  @return array list of all found date_maps   
   */
  function findInField($input,$tz_offset = 0,$format = null,$hemisphere = null){
    // TODO: I could throw an exception if no date was found
    return $this->find(true,$input,$tz_offset,$format,$hemisphere);
  }//method
  
  /**
   *  returns an information map about the start and stop timestamps in order to build
   *  decent date strings
   *  
   *  @param  integer $start_timestamp         
   *  @param  integer $stop_timestamp
   *  @param  integer $tz_offset
   *  @return parse_date_info_map   
   */
  function findInTimestamp($start_timestamp,$stop_timestamp = 0,$tz_offset = 0){
    return new parse_date_info_map($start_timestamp,$stop_timestamp,parse_date_get::timestamp($tz_offset,$this->now_timestamp));
  }//method
 
  /**
   *  finds date strings in the given input.
   *
   *  this function finds dates in the given string input
   *
   *  @param  boolean $in_field true if all of $input could be a date   
   *  @param string $input the text to be checked for dates
   *  @param integer $tz_offset the timezone offset of the user, defaults to 0
   *  @param integer $format one of either FORMAT_USA_DATE or FORMAT_WORLD_DATE
   *  @param integer $hemisphere where on earth you are, top or bottom   
   *  @return array list of all found date_parse_map instances
   */                 
  private function find($in_field,$input,$tz_offset = 0,$format = null,$hemisphere = null){
  
    if(empty($input)){ return array(); }//if
    
    $ret_map_list = array();
    
    // use the set class timezone for all calculations, restore original TZ when done...
    $orig_tz = date_default_timezone_get();
    date_default_timezone_set($this->tz);
    // set the default back to this isntance's now since parse_date_get is static, another
    // instance of the this class might have changed it...
    parse_date_get::setNow($this->now_timestamp);
    
    // break the text up into tokens, compile the tokens into "trigger" words...
    $tokenizer = new parse_date_tokens($input,$tz_offset);
    $token_list = $tokenizer->compile();
    
    ///out::e($token_list);
    
    // find any matches between the rules and the compiled tokens...
    if($date = $this->match($in_field,$token_list,$this->getRules($in_field),$tz_offset)){
    
      ///out::e(parse_date_get::rules($date->rule()));
      ///out::e($date);
      /* for($i = 0; $i < mb_strlen($input) ;$i++){
        echo $i,' - ',$input[$i],'<br>';
      }//for */
    
      $date->setText($input);
      $ret_map_list = $date->get($tz_offset);
      
      ///out::e($ret_map_list);
      
    }//if
    
    date_default_timezone_set($orig_tz);
    return $ret_map_list;
  
  }//method
  
  /**
   *  match the tokens against the rules to find matching dates 
   *
   *  @param  boolean $in_field true if all of $input could be a date (so don't bother checking word boundaries)   
   *  @param  array $token_list a list of the compiled tokens that were returned from {@link parse_date_tokens::compile()}
   *  @param  array $rule_list  a list of rules from {@link parse_date::getRules()}   
   *  @return object  parse_date_match instance
   */           
  private function match($in_field,$token_list,$rule_list,$tz_offset = 0){
  
    // canary...
    if(empty($token_list)){ return null; }//if
    if(empty($rule_list)){ return null; }//if
  
    $date = null;
    
    // go through each set of rules...
    foreach($rule_list as $rules){
    
      $i_current = $within = $i_rule = 0;
      
      ///out::e('',parse_date_get::rules($rules));
      
      while(isset($token_list[$i_current])){
      
        // reset date since we are on a new rule set iteration...
        $date = new parse_date_match();
        $i_within = -1; // when a WORD_* rule is found this is set, so if the rule ultimately fails, we can reset
        $last_word_offset = -1; // hold the last matched token's word offset
        $in_rule = false;
      
        // go through each rule in the current rule set...
        while(isset($rules[$i_rule])){
          
          $found_match = false;
          
          /*
          out::e('',''.$i_rule.' = '.parse_date_get::rules($rules[$i_rule]));
          $token_val = isset($token_list[$i_current])
            ? $token_list[$i_current]->get().' ('.get_class($token_list[$i_current]).')'
            : 'OUT OF TOKENS';
          out::e('    '.$i_current.' - value: '.$token_val); // */
          
          // canary, move onto the next set of rules since we are all out of tokens and didn't finish...
          if(!isset($token_list[$i_current])){
            ///out::e('No More Tokens');
            $date = null;
            break;
          }//if
          
          $match_map = $this->isMatch($rules[$i_rule],$token_list[$i_current],$date,$tz_offset);
          $date = $match_map['date'];
          
          if($match_map['found_match']){
            
            $current_word_offset = $token_list[$i_current]->offsetWord();
            
            $i_rule++; // we only move onto the next rule when a match is found

            // set/reset the dead token info...
            if(empty($match_map['within'])){
              
              if($in_rule){
    
                // only bother to check the word boundary if not all text can be a date...  
                if(!$in_field){
                
                  // we have a +2 fudge factor because something like month day, year the year
                  // won't be caught because day is 2 and year is 4 and 4-2 = 2 which is greater
                  // than the 1 fudge factor we had previously...
                  $boundary = (($within < 0) ? 0 : $within) + 2;
                
                  ///out::e($current_word_offset,$last_word_offset,$boundary);
                  ///out::e($token_list);
                
                  if(($current_word_offset - $last_word_offset) > $boundary){
                  
                    $found_match = false;
                    $i_current = ($i_within >= 0) ? $i_within : ($i_current - 0);
                    ///out::e('failed to find a match within word boundary');
                    $i_rule = 0;
                    break;
                  
                  }//if
                
                }//if
                
              }//if
              
              $within = 0;
              $i_within = -1;
              $i_current++;
              ///out::e('hit (i_current: '.$i_current.')');
              
            }else{
              $within += $match_map['within'];
              $i_within = $i_current;
              ///out::e('stay (within: '.$within.', i_within: '.$i_within.', i_current: '.$i_current.')');
            }//if/else

            $found_match = $match_map['found_match'];
            $last_word_offset = $current_word_offset;
            $in_rule = true;
          
          }else{
          
            $i_current++;
            $within--;
            
            ///out::e('miss (within: '.$within.', i_within: '.$i_within.', i_current: '.$i_current.')');
            
            if($within < 0){
            
              if($i_within >= 0){
                $i_current = $i_within;
              }else{
  
                if($in_rule){
    
                  // stay on the current token (we just incremented past it) since we are
                  // reseting the rules...
                  $i_current--;
                  
                }//if
                
              }//if/else
            
              $i_rule = 0; // reset the rules since we need to start over
            
              ///out::e('reset');
              break;
              
            }//if
          
          }//if/else
        
        }//while RULES
        
        if($found_match){
        
          $date->rule($rules);
          
          // we made it to the end without missing so we are done because we found a date...
          break 2;
          
        }//if
      
      }//while TOKEN
    
    }//foreach RULE_LIST

    ///out::e($date);
    return $date;
    
  }//method

  /**
   *  compare the $rule to the $token_current to see if we got a match
   *  
   *  @param  parse_date_rule $rule
   *  @param  parse_date_token  $current_token
   *  @param  parse_date_match  $date the object that will save the matched $current_token
   *  @param  integer $tz_offset
   *  @return array array with keys: 'within', 'date', and 'found_match' will always be set                     
   */
  private function isMatch($rule,$token_current,$date,$tz_offset = 0){
  
    $ret_map = array();
    $ret_map['within'] = 0;
    $found_match = false;
  
    switch($rule){
          
      case self::NUM: // any integer
      
        if($token_current instanceof parse_date_num_token){
          $date->addNum($token_current);
          $found_match = true;
        }//if
      
        break;
    
      case self::DAY_NUM: // 1-31
      
        if($token_current instanceof parse_date_num_token){
        
          if(parse_date_get::validate($token_current->get(),'#^(?:[12][0-9]|3[01]|0?[1-9])$#u')){
            $date->addDay($token_current);
            $found_match = true;
          }//if
        
        }//if
      
        break;
      
      case self::DAY_NAME: // monday, tues, wednes, etc.
      
        if($token_current instanceof parse_date_day_token){
          $date->addDayIndex($token_current);
          $found_match = true;
        }//if

        break;
      
      case self::MONTH_NAME: // sept, october, etc.
      
        if($token_current instanceof parse_date_month_token){
        
          $date->addMonth($token_current);
          $found_match = true;
        }//if
      
        break;
        
      case self::MONTH_NUM: // 1-12
      
        if($token_current instanceof parse_date_num_token){
        
          if(parse_date_get::validate($token_current->get(),'#^[0]?[1-9]|[1][0-2]$#u')){
            $token_month = new parse_date_month_token($token_current);
            $token_month->month($token_current->get());
            $date->addMonth($token_month);
            $found_match = true;
          }//if
        
        }//if
      
        break;
        
      case self::YEAR_2: // 09, etc.
      
        if($token_current instanceof parse_date_num_token){
        
          if(parse_date_get::validate($token_current->get(),'#^(?:1[4-9]|20)?[0-9]{2}$#u')){
            $token_year = new parse_date_year_token($token_current);
            $token_year->year(parse_date_get::year($token_current->get(),$tz_offset));
            $date->addYear($token_year);
            $found_match = true;
          }//if
        
        }//if
    
        break;
        
      case self::YEAR_4: // 2009
      
        if($token_current instanceof parse_date_num_token){
        
          if(parse_date_get::validate($token_current->get(),'#^(?:1[4-9]|20)[0-9]{2}$#u')){
            $token_year = new parse_date_year_token($token_current);
            $token_year->year($token_current->get());
            $date->addYear($token_year);
            $found_match = true;
          }//if
        
        }//if
      
        break;
        
      case self::PREFIX:
      
        if($token_current instanceof parse_date_prefix_token){
          $date->setPrefix($token_current);
          $found_match = true;
        }//if
      
        break;
      
      case self::TIME_ALL:
      
        if($token_current instanceof parse_date_time_interval_token){
          $date->setTimeInterval($token_current);
          $found_match = true;
        }else if($token_current instanceof parse_date_time_token){
          $date->addTime($token_current);
          $found_match = true;
        }//if
        
        break;
      
      case self::TIME_INTERVAL:
      
        if($token_current instanceof parse_date_time_interval_token){
          $date->setTimeInterval($token_current);
          $found_match = true;
        }//if
      
        break;
      
      case self::TIME:
      
        if($token_current instanceof parse_date_time_token){
          $date->addTime($token_current);
          $found_match = true;
        }//if
      
        break;
        
      case self::THROUGH:
      
        if($token_current instanceof parse_date_through_token){
          $found_match = true;
        }//if
      
        break;
        
      case self::DATE_DELIM:
      
        if($token_current instanceof parse_date_delim_token){
          $found_match = true;
        }//if
      
        break;
        
      case self::DATE_IMPLIED:
      
        if($token_current instanceof parse_date_implied_token){
          $date->addImplied($token_current);
          $found_match = true;
        }//if
      
        break;
        
      case self::DATE_IMPLIED_MONTH:
      
        if(($token_current instanceof parse_date_implied_token) && $token_current->isMonth()){
          $date->addImplied($token_current);
          $found_match = true;
        }//if
      
        break;
    
      case self::WORD_5:
        $ret_map['within']++;
      case self::WORD_4:
        $ret_map['within']++;
      case self::WORD_3:
        $ret_map['within']++;
      case self::WORD_2:
        $ret_map['within']++;
      case self::WORD_1:
        $ret_map['within']++;
        
        $found_match = true;
        break;
        
      case self::TIME_NUM:
      
        if($token_current instanceof parse_date_num_token){
          
          if(parse_date_get::validate($token_current->get(),'#^(?:[0-1]?[0-9]|[2][0-3])$#u')){
            
            $token_time = new parse_date_time_token($token_current);
            $token_time->setTime(intval($token_current->get()),0);
            
            if($date->hasTime()){
            
              $token_time_interval = new parse_date_time_interval_token(-1,-1);
              $token_time_interval->start($date->time());
              $token_time_interval->stop($token_time);
              
              $date->clearTime();
              $date->setTimeInterval($token_time_interval);
            
            }else{
            
              $date->addTime($token_time);
              
            }//if/else
            
            $found_match = true;
            
          }//if
          
        }//if
        
        break;
        
      default:
        throw new Exception('invalid rule token('.$rule.'), please check '.__CLASS__.'getRules()');
        break;
        
    }//switch
    
    $ret_map['date'] = $date;
    $ret_map['found_match'] = $found_match;
    
    return $ret_map;
  
  }//method

}//class

/**
 *  the base class for all the token classes and map class, just adds some functions
 *  that are common 
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-18-09
 *  @project parse_date
 ******************************************************************************/   
class parse_date_base {

  /**
   *  holds all the values this instance can set
   *
   */        
  protected $val_map = array();

  /**
   *  blanket function that does the getting/setting of values.
   *  
   *  if $val is null, then the $key's current value is checked if it exists, if
   *  it doesn't exist then $default_val is returned
   *  @access private   
   *  
   *  @param  string|integer  $key  the key whose value you want to fetch
   *  @param  mixed $val  the val you want to set key to, if null then key is returned
   *  @Param  mixed $default_val  if $key isn't found, then return $default_val   
   *  @return mixed if $val is null then the $key's val is returned, otherwise returns nothing
   */     
  protected function val($key,$val = null,$default_val = null){
    if($val === null){
      return isset($this->val_map[$key]) ? $this->val_map[$key] : $default_val; 
    }else{
      $this->val_map[$key] = $val;
    }//if/else
  }//method
  
  /**
   *  clear a $key from the {@link $val_map}
   */
  protected function clear($key){
    if(isset($this->val_map[$key])){ unset($this->val_map[$key]); }//if
  }//method
  
  /**
   *  check if a given key is in the {@link $val_map} and non-empty
   *  @return boolean true if key exists and is non-empty
   */
  protected function has($key){ return !empty($this->val_map[$key]); }//method
  
  /**
   *  check if a given key is in the {@link $val_map}
   *  @return boolean true if key exists and is non-empty
   */
  protected function exists($key){ return isset($this->val_map[$key]); }//method

}//class

/**
 *  every special word found in the tokenized text becomes an instance of this class
 *  
 *  this class contains info about the token  
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-15-09
 *  @project parse_date
 ******************************************************************************/
class parse_date_token extends parse_date_base {

  /**
   *  override default constructor
   *   
   *  @param  mixed $args,... argument count:   
   *                           - 3- - $word_offset,$char_offset,$char_stop_offset   
   *                           - 2 - $word_offset,$char_offset
   *                           - 1 - parse_date_token instance
   *                           - 0 - $word_offset and $char_offset are set to -1             
   */
  function __construct(){
  
    $func_list = func_get_args();
    if(empty($func_list)){
    
      $this->offsetWord(-1);
      $this->offsetChar(-1);
      $this->val_map['base_char_list'] = array();
      $this->keyword(new parse_date_keyword());
    
    }else{    
      
      if(isset($func_list[1])){
      
        $this->offsetWord($func_list[0]);
        $this->offsetChar($func_list[1]);
        if(isset($func_list[2])){ $this->offsetCharStop($func_list[2]); }//if
      
        $this->val_map['base_char_list'] = array();
        $this->keyword(new parse_date_keyword());
      
      }else{
      
        $this->val_map = $func_list[0]->vals();
      
      }//if/else
      
    }//if/else
    
  }//method
  
  function vals(){ return $this->val_map; }//method
  
  /**
   *  get/set the token's index  
   *
   *  the index is where the token lives in the tokens list. If the token was made up
   *  of multiple tokens when it was compiled, then index would point to the last token
   *  the current token was made up of. Think of it as index() + 1 = next() in a linked list
   *  
   *  this is used in {@link tokens::compile()} to find out where the compiler is in the found
   *  tokens list         
   *  
   *  @param  integer $val  the index of the token
   *  @return integer if $val is null then the current index is returned   
   */
  function index($val = null){ return $this->val('base_i',$val,0); }//method
  
  /**
   *  get/set the keyword that was used to create this token
   */     
  function keyword($val = null){ return $this->val('base_keyword',$val,null); }//method

  function set($word){
    $this->val_map['base_text'] = $word;
  }//method
  
  function append($char){
    $this->val_map['base_char_list'][] = $char;
  }//method
  
  function get(){
    
    if(!isset($this->val_map['base_text'])){
      $this->val_map['base_text'] = join('',$this->val_map['base_char_list']);
    }//if
  
    return $this->val_map['base_text'];
    
  }//method

  function getNumeric(){ return preg_replace('#\D+#u','',$this->get()); }//method

  function getClean(){
    return mb_strtolower(trim($this->get(),'.,?!'));
  }//method
  
  function hasText(){ return $this->has('base_char_list'); }//method
  
  function offsetChar($val = null){ return $this->val('base_char_offset',$val,0); }//method
  
  /**
   *  while {@link offsetChar()} gets the start offset of the token, this will get the
   *  stop offset of the token.
   *  
   *  if the stop isn't explicitly set then this will assume start_offset + base_text length is where
   *  the stop offset would be.   
   *
   *  @param  integer $val  the stop offset if you want to explicitely set, if null then return the current set stop offset
   *  @return integer the current stop char offset   
   */
  function offsetCharStop($val = null){
  
    $ret_int = 0;
    if($val === null){
    
      if($this->exists('base_char_offset_stop')){
        $ret_int = $this->val('base_char_offset_stop',$val,0);
      }else{
        $ret_int = $this->offsetChar() + mb_strlen($this->get());
      }//if/else
    
    }else{
      $ret_int = $this->val('base_char_offset_stop',$val,0);
    }//if/else
    
    return $ret_int;
    
  }//method
  
  /**
   *  get/set the word offset (which word number this token was in input)
   *  
   *  NOTE: this function always points to the last word of the token, so in the
   *  case of a time token it would point to the meridian over the hour, while 
   *  {@link offsetChar()} would point to the char offset of the hour
   *
   *  @return integer the word offset, or word index of this token   
   */           
  function offsetWord($val = null){ return $this->val('base_word_offset',$val,0); }//method
  
}//class

class parse_date_num_token extends parse_date_token {}//class
class parse_date_day_token extends parse_date_token {
  function wday($val = null){ return $this->val('day_wday',$val,0); }//method
}//class
class parse_date_year_token extends parse_date_token {
  function year($val = null){ return $this->val('year_year',$val,0); }//method
}//class
class parse_date_month_token extends parse_date_token {
  function month($val = null){ return $this->val('month_month',$val,0); }//method
}//class
class parse_date_through_token extends parse_date_token {}//class
class parse_date_delim_token extends parse_date_token {}//class
class parse_date_prefix_token extends parse_date_token {
  function isNext(){ return $this->keyword()->isValue(1); }//method
  function isRecurring(){ return $this->keyword()->isValue(5); }//method
}//class
class parse_date_implied_token extends parse_date_token {
  function isWeek(){ return $this->keyword()->isValue(1); }//method
  function isMonth(){ return $this->keyword()->isValue(2); }//method
  function isDay(){ return $this->keyword()->isValue(3); }//method
  function isHour(){ return $this->keyword()->isValue(4); }//method
  function isMinute(){ return $this->keyword()->isValue(5); }//method
}//class
class parse_date_time_interval_token extends parse_date_token {
  function start($val = null){ return $this->val('time_interval_start_time_token',$val,0); }//method
  function stop($val = null){ return $this->val('time_interval_stop_time_token',$val,0); }//method
}//class
class parse_date_time_token extends parse_date_token {

  /**
   *  set the time for this token
   *     
   *  sets the hour to military time hours
   *  
   *  @param  integer|string  $hour the hour to set
   *  @param  integer|string  $minute the minutes (0-59)  
   *  @param  string  $meridian the am/pm of the given hour
   *  @param  integer $increment  how many seconds should be added to the final timestamp   
   */        
  function setTime($hour,$minute,$meridian = '',$increment = 0){
    $this->minute($minute);
    $this->meridian($meridian);
    $this->hour($hour);
    $this->increment($increment);
  }//method
  
  function isMil($val = null){ return $this->val('time_is_mil',$val,false); }//method
  
  /**
   *  returns the hour in military time format (0-23)
   *  
   *  @return integer an integer from 0-23
   */        
  function hourMil(){
  
    $hour = (int)$this->hour();
    if(!$this->isMil()){
      if($this->isPm()){
        if($hour < 12){ $hour = 12 + $hour; }//if
      }else{
        if($hour === 12){ $hour = 0; }//if
      }//if/else
    }//if
  
    return $hour;
  
  }//method
  
  function isAm(){
    $meridian = $this->meridian();
    return empty($meridian) ? true : (bool)preg_match('#a\.?(?:m\.?)?#iu',$meridian);
  }//method

  function isPm(){
    $meridian = $this->meridian();
    return empty($meridian) ? false : (bool)preg_match('#p\.?(?:m\.?)?#iu',$meridian);
  }//method

  function hour($val = null){ return $this->val('time_hour',$val,0); }//method
  function minute($val = null){ return $this->val('time_minute',$val,0); }//method
  function increment($val = null){ return $this->val('time_increment',$val,0); }//method
  
  /**
   *  get/set the time meridian (am/pm) of the time
   *  
   *  @param  string  $val  the meridian value,       
   *  @return string  the meridian value
   */        
  function meridian($val = null){ return $this->val('time_meridian',$val,''); }//method
  function hasMeridian(){ return !empty($this->val_map['time_meridian']); }//method


}//class

/**
 *  this holds the found "special" tokens that where tokenized, these include stuff
 *  like trigger keywords and numbers 
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-15-09
 *  @project parse_date
 ******************************************************************************/
class parse_date_tokens {

  const TYPE_MONTH = 1;
  const TYPE_PREFIX = 2;
  const TYPE_TIME_MERIDIAN = 3;
  const TYPE_TIME_DELIM = 4;
  const TYPE_TIME = 5;
  const TYPE_DAY_NAME = 6;
  const TYPE_ORDINAL_SUFFIX = 7;
  const TYPE_DELIM_COLLECTIVE = 8;
  const TYPE_DELIM_INDIVIDUAL = 9;
  const TYPE_DELIM_DATE = 10;
  const TYPE_NUMBER = 11;
  const TYPE_NUMBER_NAME = 12;
  const TYPE_IMPLIED_DATE = 13;
  
  const INDEX_TYPE = 0;
  const INDEX_TYPE_TOKEN = 1;
  const INDEX_INFO_MAP = 2;
  
  /**
   *  holds all the trigger keywords the parser looks for
   *  @var  parse_date_keywords   
   */     
  private $keywords = null;
  
  /**
   *  holdas a list of all the parse_date_token objects that were found
   */     
  private $found_token_list = array();
  
  /**
   *  holds where the token is in the {@link $found_toke_list}
   *  @var  integer   
   */     
  private $found_token_i = 0;
  
  /**
   *  override default constructor
   *  
   *  @param  string  $input  the text that will tokenized   
   *  @param integer $tz_offset the timezone offset of the user, defaults to 0      
   */     
  function __construct($input = '',$tz_offset = 0){
    $this->setKeywords($tz_offset);
    $this->parse($input);
  }//method
  
  /**
   *  this goes through input and parses keywords and numbers
   *     
   *  @param  string  $input  the input to be tokenized
   *  @param integer $tz_offset the timezone offset of the user, defaults to 0         
   *  @return object  parse_date_tokens instance that contains all the found parse_date_token instances            
   */        
  function parse($input){
  
    // canary...
    if(empty($input)){ return false; }//if

    $total_len = mb_strlen($input);
    $char_i = $word_i = 0;
    $word = new parse_date_token($word_i,$char_i);
    
    // go through each character and find keywords...
    while($char_i < $total_len){
    
      if(ctype_digit($input[$char_i])){ // we have found a number
      
        if($word->hasText()){
          $this->saveToken($word);
          $word = new parse_date_token(++$word_i,$char_i);
        }//if
      
        do{
        
          $word->append($input[$char_i++]);
        
        }while(isset($input[$char_i]) && ctype_digit($input[$char_i]));
        
        $this->saveNumber($word);
        $word = new parse_date_token(++$word_i,$char_i);
        
      }else if(ctype_space($input[$char_i])){ // we are finishing the current word and starting another
      
        // ignore all the whitespace...
        while(isset($input[++$char_i]) && ctype_space($input[$char_i]));
      
        if($word->hasText()){
          $this->saveToken($word);
          $word = new parse_date_token(++$word_i,$char_i);
        }//if
      
      }else{
      
        // dash is collective keyword, but also used in some keywords, so it needs to be treated special...
        if($input[$char_i] == '-'){
        
          // here we need to do a one char look behind to make sure the last char isn't a "y", this
          // is a total hack to make sure keywords like "twenty-four" are supported...
          if(isset($input[$char_i - 1]) && ($input[$char_i - 1] != 'y')){
          
            if($word->hasText()){
              $this->saveToken($word);
              $word = new parse_date_token(++$word_i,$char_i);
            }//if
          
          }//if
          
          // now let's just save the dash...
          $word->append($input[$char_i++]);
          $this->saveToken($word);
          $word = new parse_date_token(++$word_i,$char_i);
        
        }else{
      
          $word->append($input[$char_i++]);
          
        }//if/else
      
      }//if/else if.../else
    
    }//while
    
    if($word->hasText()){ $this->saveToken($word); }//if
    
    return true;
  
  }//method
  
  /**
   *  go through and compile the tokens into rules, this will allow {@link match()}
   *  to quickly go through and find appropriate matches fast instead of having to
   *  repeatedly check the same things over and over
   *  
   *  @param  object  $tokens the parse_date_tokens instance returned from {@link parse()}
   *  @return array a list of the found rules array(array(RULE_INDEX => parse_date_token)...)
   */        
  function compile(){
  
    $ret_list = array();
    $i_current = 0;
  
    while($token = $this->get($i_current)){

      $ret_token = null;
      $keyword = $token->keyword();
      
      switch($keyword->type()){
      
        case self::TYPE_NUMBER:
      
          // we need to see if we have a time...
          $ret_token = $this->compileTimeInterval($i_current);
          if(empty($ret_token)){
            $ret_token = $this->compileTime($i_current);
          }//if
          if(empty($ret_token)){
            $ret_token = new parse_date_num_token($token);
          }//if
          
          break;
      
        case self::TYPE_NUMBER_NAME:
        
          $ret_token = new parse_date_num_token($token);
          $num_int = $ret_token->keyword()->value();
          if(!empty($num_int)){
            $ret_token->set($num_int);
          }//if
          break;
      
        case self::TYPE_DAY_NAME:
        
          $ret_token = new parse_date_day_token($token);
          $day_int = $ret_token->keyword()->value();
          if(!empty($day_int)){
            $ret_token->wday($day_int);
          }//if
          break;
          
        case self::TYPE_MONTH:
        
          $ret_token = new parse_date_month_token($token);
          $month_int = $ret_token->keyword()->value();
          if(!empty($month_int)){
            $ret_token->month($month_int);
          }//if
          break;
        
        case self::TYPE_PREFIX:
        
          $ret_token = new parse_date_prefix_token($token);
          break;
        
        case self::TYPE_TIME:
          
          // we need to see if we have a time interval...
          $ret_token = $this->compileTimeInterval($i_current);
          if(empty($ret_token)){
            $ret_token = $this->compileTime($i_current);
          }//if
          
          break;
        
        case self::TYPE_DELIM_COLLECTIVE:
        
          $ret_token = new parse_date_through_token($token);
          break;
          
        case self::TYPE_DELIM_DATE:
        
          $ret_token = new parse_date_delim_token($token);
          break;
          
        case self::TYPE_IMPLIED_DATE:
        
          $ret_token = new parse_date_implied_token($token);
          break;
        
        ///case self::TYPE_DELIM_INDIVIDUAL:
        ///case self::TYPE_TIME_MERIDIAN:
        ///case self::TYPE_TIME_DELIM:
      
      }//switch
      
      if(!empty($ret_token)){
        $i_current = $ret_token->index();
        $ret_list[] = $ret_token;
      }//if
      
      $i_current++;
    
    }//while
  
    return $ret_list;
  
  }//method
  
  private function get($i,$type = null){
    $token_ret = null;
    if(!empty($this->found_token_list[$i])){
      $token_ret = $this->found_token_list[$i];
      if($type !== null){
        if(!$token_ret->keyword()->isType($type)){ $token_ret = null; }//if
      }//if
    }//if
    return $token_ret;
  }//method
  private function has($i){ return !empty($this->found_token_list[$i]); }//method
  
  /**
   *  since time can be made up of either a TIME token, or and NUM DELIM NUM MERIDIAN combination
   *  we need a function to see if we have a time token
   *  
   *  @param  integer $i_start  where the function should start looking in the tokens list
   *  @return parse_data_time_token|parse_date_time_interval_token  if a valid time sequence is found
   */        
  private function compileTime($i_start){
  
    // canary...
    if(!$this->has($i_start)){ return null; }//if
    
    $token_ret = null;
    $token_current = $this->get($i_start);
    $token_meridian = null;
    $i_current = $i_start;
    
    if($token_current->keyword()->isType(self::TYPE_TIME)){
    
      $keyword = $token_current->keyword();
      $token_start = $token_stop = null;
    
      if($keyword->hasTimeStart()){
    
        $keyword_time = $keyword->timeStart();
        $token_start = new parse_date_time_token($token_current);
        $token_start->setTime(
          $keyword_time['hour'],
          $keyword_time['minute'],
          $keyword_time['meridian'],
          $keyword_time['increment']
        );
    
      }//if
      
      if($keyword->hasTimeStop()){
    
        $keyword_time = $keyword->timeStop();
        $token_stop = new parse_date_time_token($token_current);
        $token_stop->setTime(
          $keyword_time['hour'],
          $keyword_time['minute'],
          $keyword_time['meridian'],
          $keyword_time['increment']
        );
        
        $token_ret = new parse_date_time_interval_token($token_current);
        $token_ret->start($token_start);
        $token_ret->stop($token_stop);
    
      }else{
      
        $token_ret = $token_start;
      
      }//if/else
    
    }else if($token_current->keyword()->isType(self::TYPE_NUMBER)){
    
      // we are looking for the form 8:00, or 8pm, or 8:00pm
    
      // we have a number, so we have an hour
      // NOTE: we had the regex: '#^(?:[0-1]?[0-9]|[2][0-4])$#u' to check for the hour, but that doesn't
      // allow for HOURMIN (eg, 1200) so we took it out...
      $token_hour = $token_current;
      
      if($token_delim = $this->get($i_start + 1,self::TYPE_TIME_DELIM)){
      
        if($token_minute = $this->get($i_start + 2,self::TYPE_NUMBER)){
        
          if(parse_date_get::validate($token_hour->get(),'#^(?:[0-1]?[0-9]|[2][0-3])$#u')){
        
            if(parse_date_get::validate($token_minute->getNumeric(),'#^[0-5][0-9]$#u')){
            
              // check for a meridian...
              $token_meridian = $this->get($i_start + 3,self::TYPE_TIME_MERIDIAN);
              ///$word_offset = 0;
              $char_offset_stop = 0;
              $meridian = null;
              if(empty($token_meridian)){
                $char_offset_stop = $token_minute->offsetCharStop();
                $i_current = $token_minute->index();
              }else{
                $meridian = $token_meridian->getClean();
                $char_offset_stop = $token_meridian->offsetCharStop();
                $i_current = $token_meridian->index();
              }//if
              
              // build a new map...
              $token_ret = new parse_date_time_token($token_hour->offsetWord(),$token_hour->offsetChar(),$char_offset_stop);
              $token_ret->setTime(intval($token_hour->get()),intval($token_minute->get()),$meridian);
              
            }//if/else
            
          }//if
        
        }//if
      
      }else{
      
        if($token_meridian = $this->get($i_start + 1,self::TYPE_TIME_MERIDIAN)){
        
          list($hour_str,$minute_str) = parse_date_get::timeBits($token_hour->getNumeric());
          
          if(parse_date_get::validate($hour_str,'#^(?:[0-1]?[0-9]|[2][0-3])$#u')){
        
            if(empty($minute_str) || parse_date_get::validate($minute_str,'#^[0-5][0-9]$#u')){
            
              $token_ret = new parse_date_time_token(
                $token_hour->offsetWord(),
                $token_hour->offsetChar(),
                $token_meridian->offsetCharStop()
              );
              $token_ret->setTime(intval($hour_str),intval($minute_str),$token_meridian->getClean());
              $i_current = $token_meridian->index();
        
            }//if
            
          }//if
        
        }//if
      
      }//if/else
      
      if(!empty($token_ret)){
      
        $token_ret->index($i_current);
        
      }//if
      
    }//if/else if
    
    return $token_ret;
  
  }//method
  
  /** 
   *  check for a time interval
   *  
   *  a time interval is defined as either 8-9:30 or 8:00 - 9:30       
   *
   *  @param  integer $i_start  where the function should start looking in the tokens list
   *  @return object  parse_data_time_interval_token if a valid time sequence is found     
   */
  private function compileTimeInterval($i_start){
  
    $has_start_time = $has_stop_time = false;
    $token_ret = $token_start = $token_stop = null;
    $i_current = $i_start;
    $start_is_time = false; // true if the start token is an actual time token
  
    // possible: 8-9:30 or 8:00 - 9:30
    
    // check for the TIME THROUGH TIME (eg, 8:00 - 9:30) first...
    if($token_start = $this->compileTime($i_current)){
    
      $has_start_time = true;
      $start_is_time = true;
      $i_current = $token_start->index();
      
      if($token_start instanceof parse_date_time_interval_token){
      
        $token_ret = $token_start;
        $has_stop_time = true;
      
      }//if
        
    }else{
    
      // well, that failed, so try for a NUM THROUGH TIME (eg, 8-9:30)... 
      if($token_hour = $this->get($i_current,self::TYPE_NUMBER)){
        $token_start = new parse_date_time_token($token_hour);
        list($hour_str,$min_str) = parse_date_get::timeBits($token_hour->get());
        $token_start->setTime($hour_str,$min_str);
      }//if
    
    }//if
    
    // now we need to finish the interval by getting THROUGH TIME...
    if(!empty($token_start) && !$has_stop_time){
    
      // now try and get a through...
      if($token_through = $this->get(++$i_current,self::TYPE_DELIM_COLLECTIVE)){
      
        // make sure the start time and the through are right next to each other...
        if($start_is_time || (($token_through->offsetWord() - $token_start->offsetWord()) <= 1)){
      
          // now try for another time map...
          if($token_stop = $this->compileTime($i_current + 1)){
          
            // success...
            $has_start_time = $has_stop_time = true;
            
          }else{
          
            if($has_start_time){
            
              // try for just an hour since we no we already have one time trigger and a through...
              if($token_hour = $this->get(++$i_current,self::TYPE_NUMBER)){
            
                $has_stop_time = true;
                $token_stop = new parse_date_time_token($token_hour);
                $token_stop->setTime($token_hour->get(),0);
                $token_stop->index($token_hour->index());
                
              }//if
              
            }//if
          
          }//if/else
          
        }//if
      
      }//if
    
    }//if
    
    if(empty($token_ret) && ($has_start_time && $has_stop_time)){
        
      // account for something like: 10:00-2:00
      $token_ret = new parse_date_time_interval_token(
        $token_stop->offsetWord(),
        $token_start->offsetChar(),
        $token_stop->offsetCharStop()
      );
      $token_ret->start($token_start);
      $token_ret->stop($token_stop);
      $token_ret->index($token_stop->index());
      
    }//if
  
    return $token_ret;
  
  }//method
  
  /**
   *  output the found map, this is only really useful for debugging
   *  
   *  @param  integer $word_offset  if you want to start at a certain offset (to see what's left) then pass it in
   *  @return array the found map, suitable for output         
   */        
  function out($i = 0){
  
    if(!empty($i)){
    
      $found_token_list = array();
      while($token = $this->get($i++)){
      
        $found_token_list[] = $token;
      
      }//while
  
      return $found_token_list;
  
    }//if
  
    return $this->found_token_list;
    
  }//method

  /**
   *  saves the $word into the {@link $found_map}
   *  
   *  @param  object  {@link parse_date_token} instance
   *  @return boolean         
   */
  function saveToken(parse_date_token $word){
  
    $ret_bool = false;
    $cleaned_word = $word->getClean();
    if($this->keywords->match($cleaned_word)){
      $ret_bool = $this->save($word,$this->keywords->get($cleaned_word));
    }//if
    
    return $ret_bool;
    
  }//word
  
  /**
   *  saves the $word into the {@link $found_map} with a number type
   *  
   *  @param  object  {@link parse_date_token} instance
   *  @return boolean         
   */
  function saveNumber(parse_date_token $word){
    $keyword = new parse_date_keyword('','',self::TYPE_NUMBER);
    return $this->save($word,$keyword);
  }//method
  
  /**
   *  the private setter function for all the public save* functions
   *     
   *  @param  parse_date_token  the found word
   *  @param  parse_date_keyword  the keyword that was found, if it exists   
   *  @param  boolean         
   */
  private function save(parse_date_token $word,parse_date_keyword $keyword){
    $word->keyword($keyword);
    $word->index($this->found_token_i);
    $this->found_token_list[$this->found_token_i++] = $word;
    return true;
  }//method

  /**
   *  returns the keyword list this class uses to tokenize a string
   *  @return parse_date_keywords   
   */
  function getKeywords(){ return $this->keywords; }//method
  
  /**
   *  inits all the keywords the class uses to find signal tokens among the noise
   *  
   *  @param  integer $tz_offset  the timezone that should be used to calculate some of the values
   */        
  private function setKeywords($tz_offset = 0){

    $timestamp = parse_date_get::timestamp($tz_offset);
    $time_map = parse_date_get::dateMap($timestamp);
    $today = $time_map['wday'];
  
    $this->keywords = new parse_date_keywords();
    
    /* place numbers... */
    $this->keywords->add(new parse_date_keyword('first',1,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('second',2,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('third',3,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('fourth',4,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('fifth',5,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('sixth',6,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('seventh',7,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('eighth',8,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('ninth',9,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('tenth',10,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('eleventh',11,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twelfth',12,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirteenth',13,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('fourteenth',14,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('fifteenth',15,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('sixteenth',16,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('seventeenth',17,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('eighteenth',18,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('nineteenth',19,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentieth',20,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyfirst',21,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-first',21,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentysecond',22,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-second',22,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentythird',23,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-third',23,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyfourth',24,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-fourth',24,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyfifth',25,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-fifth',25,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentysixth',26,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-sixth',26,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyseventh',27,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-seventh',27,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyeighth',28,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-eighth',28,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyninth',29,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-ninth',29,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirtieth',30,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirtyfirst',31,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirty-first',31,self::TYPE_NUMBER_NAME));
    
    /* name of numbers... */
    $this->keywords->add(new parse_date_keyword('one',1,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('two',2,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('three',3,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('four',4,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('five',5,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('six',6,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('seven',7,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('eight',8,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('nine',9,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('ten',10,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('eleven',11,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twelve',12,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirteen',13,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('fourteen',14,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('fifteen',15,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('sixteen',16,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('seventeen',17,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('eighteen',18,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('nineteen',19,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty',20,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyone',21,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-one',21,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentytwo',22,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-two',22,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentythree',23,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-three',23,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyfour',24,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-four',24,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyfive',25,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-five',25,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentysix',26,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-six',26,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyseven',27,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-seven',27,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentyeight',28,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-eight',28,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twentynine',29,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('twenty-nine',29,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirty',30,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirtyone',31,self::TYPE_NUMBER_NAME));
    $this->keywords->add(new parse_date_keyword('thirty-one',31,self::TYPE_NUMBER_NAME));
    
    /* Months... */
    $this->keywords->add(new parse_date_keyword('january',1,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('jan',1,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('february',2,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('feb',2,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('march',3,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('mar',3,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('april',4,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('apr',4,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('may',5,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('june',6,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('jun',6,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('july',7,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('jul',7,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('august',8,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('aug',8,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('september',9,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('sept',9,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('october',10,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('oct',10,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('november',11,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('nov',11,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('december',12,self::TYPE_MONTH));
    $this->keywords->add(new parse_date_keyword('dec',12,self::TYPE_MONTH));
    
    /* Days... */
    $this->keywords->add(new parse_date_keyword('monday',1,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('mon',1,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('tuesday',2,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('tues',2,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('wednesday',3,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('wednes',3,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('weds',3,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('thursday',4,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('thurs',4,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('thur',4,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('thu',4,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('friday',5,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('fri',5,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('saturday',6,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('sunday',7,self::TYPE_DAY_NAME));
    $this->keywords->add(new parse_date_keyword('tomorrow',$today + 1,self::TYPE_DAY_NAME));
    
    $keyword = new parse_date_keyword('today',$today,self::TYPE_DAY_NAME);
    $keyword->subType(1);
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('now',$today,self::TYPE_DAY_NAME);
    $keyword->timeStart($time_map['hours'],0,'');
    $keyword->timeStop(($time_map['hours'] + 2) % 24,0,'');
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('tonight',$today,self::TYPE_DAY_NAME);
    $keyword->timeStart(18,0,'pm');
    $keyword->timeStop(23,59,'pm',60);
    $keyword->meridianDefault('pm');
    $keyword->typeTime(1);
    $keyword->timeValue('night');
    $keyword->subType(1);
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('soon',$today,self::TYPE_DAY_NAME);
    $keyword->timeStart(($time_map['hours'] + 1) % 24,$time_map['minutes'],'');
    $keyword->timeStop(($time_map['hours'] + 3) % 24,$time_map['minutes'],'');
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('weekend',6,self::TYPE_DAY_NAME);
    $keyword->duration(1);
    $this->keywords->add($keyword);
    
    /* implied dates... */
    $this->keywords->add(new parse_date_keyword('week',1,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('weeks',1,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('month',2,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('months',2,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('day',3,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('days',3,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('hour',4,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('hours',4,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('minute',5,self::TYPE_IMPLIED_DATE));
    $this->keywords->add(new parse_date_keyword('minutes',5,self::TYPE_IMPLIED_DATE));
    
    /* Ordinal Suffixes... */
    /* $this->keywords->add(new parse_date_keyword('st',1,self::TYPE_ORDINAL_SUFFIX));
    $this->keywords->add(new parse_date_keyword('nd',2,self::TYPE_ORDINAL_SUFFIX));
    $this->keywords->add(new parse_date_keyword('rd',3,self::TYPE_ORDINAL_SUFFIX));
    $this->keywords->add(new parse_date_keyword('th',4,self::TYPE_ORDINAL_SUFFIX)); */
    
    /* individual deliminiters... */
    $this->keywords->add(new parse_date_keyword('&',1,self::TYPE_DELIM_INDIVIDUAL));
    $this->keywords->add(new parse_date_keyword('and',2,self::TYPE_DELIM_INDIVIDUAL));
    $this->keywords->add(new parse_date_keyword('or',3,self::TYPE_DELIM_INDIVIDUAL));
    $this->keywords->add(new parse_date_keyword(',',4,self::TYPE_DELIM_INDIVIDUAL));
    
    /* collective deliminiters... */
    $this->keywords->add(new parse_date_keyword('-',1,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('through',2,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('thru',3,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('to',4,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('til',5,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('till',6,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('until',7,self::TYPE_DELIM_COLLECTIVE));
    $this->keywords->add(new parse_date_keyword('—',8,self::TYPE_DELIM_COLLECTIVE));
    
    /* time stuff... */
    $this->keywords->add(new parse_date_keyword('am',1,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('a.m.',2,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('a.m',3,self::TYPE_TIME_MERIDIAN));
    // disabled because: "for every 5 pounds you lose (with a cap of course)." became 5am, this could
    // be fixed by having compileTime, when it checks for a meridian it should check the word offset
    // to make sure the meridian is within like 3...
    ///$this->keywords->add(new parse_date_keyword('a',4,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('pm',5,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('p.m.',6,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('p.m',7,self::TYPE_TIME_MERIDIAN));
    ///$this->keywords->add(new parse_date_keyword('p',8,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword(':',9,self::TYPE_TIME_DELIM));
    // these are now implied, I am leaving hr as a meridian though...
    ///$this->keywords->add(new parse_date_keyword('hour',10,self::TYPE_TIME_MERIDIAN));
    ///$this->keywords->add(new parse_date_keyword('hours',11,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('hr',12,self::TYPE_TIME_MERIDIAN));
    $this->keywords->add(new parse_date_keyword('hrs',13,self::TYPE_TIME_MERIDIAN));
    
    $keyword = new parse_date_keyword('noon',14,self::TYPE_TIME);
    $keyword->timeStart(12,0,'pm');
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('midnight',15,self::TYPE_TIME);
    $keyword->timeStart(11,59,'pm',60);
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('night',16,self::TYPE_TIME);
    $keyword->timeStart(19,0,'pm');
    $keyword->typeTime(1);
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('evening',17,self::TYPE_TIME);
    $keyword->timeStart(18,0,'pm');
    $keyword->timeStop(23,0,'pm');
    $keyword->typeTime(2);
    $keyword->meridianDefault('pm');
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('afternoon',18,self::TYPE_TIME);
    $keyword->timeStart(12,0,'pm');
    $keyword->timeStop(18,0,'pm');
    $keyword->typeTime(3);
    $keyword->meridianDefault('pm');
    $this->keywords->add($keyword);
    
    $keyword = new parse_date_keyword('morning',19,self::TYPE_TIME);
    $keyword->timeStart(6,0,'am');
    $keyword->timeStop(12,0,'pm');
    $keyword->typeTime(4);
    $keyword->meridianDefault('am');
    $this->keywords->add($keyword);
    
    /* prefixes... */
    $this->keywords->add(new parse_date_keyword('next',1,self::TYPE_PREFIX));
    $this->keywords->add(new parse_date_keyword('this',2,self::TYPE_PREFIX));
    $this->keywords->add(new parse_date_keyword('on',3,self::TYPE_PREFIX));
    $this->keywords->add(new parse_date_keyword('for',4,self::TYPE_PREFIX));
    $this->keywords->add(new parse_date_keyword('every',5,self::TYPE_PREFIX));
    
    /* date deliminiters, these are used for mm/dd/yyyy type dates... */
    $this->keywords->add(new parse_date_keyword('/',1,self::TYPE_DELIM_DATE));
    $this->keywords->add(new parse_date_keyword('|',2,self::TYPE_DELIM_DATE));
    $this->keywords->add(new parse_date_keyword('_',2,self::TYPE_DELIM_DATE));
    $this->keywords->add(new parse_date_keyword('\\',3,self::TYPE_DELIM_DATE));
  
  }//method

}//class

/**
 *  this is a static helper class that contains functions that any of the other
 *  classes might need to use to parse/create the dates 
 *  
 *  this is a private class of {@link parse_date}
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-15-09
 *  @project parse_date
 ******************************************************************************/
class parse_date_get {

  /**
   *  how many seconds a date of type DAY will have in it
   */     
  const DAY = 86400;
  
  /**
   *  sometimes when calling {@link timestamp()} without a timestamp you don't want
   *  NOW to be returned, if this has a non-zero value this will be returned instead   
   *  @var  integer
   */
  public static $DEFAULT_TIMESTAMP = 0;

  /**
   *  takes input like 2030 and breaks it out into hour (20) and minute (30)
   *
   *  @param  string  $time_str
   *  @return array array($hour_str,$minute_str)   
   */
  static function timeBits($time_str){
  
    // canary...
    if(empty($time_str)){ return array(0,0); }//if
  
    $hour_str = (string)$time_str;
    $minute_str = 0;
    $hour_str_len = mb_strlen($hour_str);
    if($hour_str_len == 4){
      $minute_str = $hour_str[2].$hour_str[3];
      $hour_str = $hour_str[0].$hour_str[1];
    }else if($hour_str_len == 3){
      $minute_str = $hour_str[1].$hour_str[2];
      $hour_str = $hour_str[0];
    }//if/else if
  
    return array($hour_str,$minute_str);
  
  }//method

  /**
   *  if you want NOW when {@link timetsamp()} is called to be something other than
   *  NOW (eg, you are dealing with dates in the past and you want the found dates to
   *  reflect that) then set the past timestamp with this   
   *
   *  @param  integer $timestamp  the timestamp that will be used in place of time() in timestamp()      
   */        
  static function setNow($timestamp = 0){
    self::$DEFAULT_TIMESTAMP = (int)$timestamp;
  }//method

  /**
     *  returns a date map with an updated wday that goes 1 for the first day of the week to 7
     *  for the last day of the week instead of 0 - 6
     *  
     *  @param  integer $timestamp
     *  @return array   the same array returned by the built in getdate()
     */              
    static function dateMap($timestamp)
    {
        $ret_map = getdate($timestamp);
        
        // now compensate for getdate returning a dumb day index...
        $ret_map['wday'] = empty($ret_map['wday']) ? 7 : $ret_map['wday'];
        
        // we also want days in the month...
        $ret_map['dim'] = date('t',$timestamp);
        
        return $ret_map;
    
    }//method

  /**
   *  this is mainly a debug function, what it does is take a list of rules and print out
   *  the const names for those rules that is human readable instead of just a list of ints
   *  
   *  @since  10-27-09
   *      
   *  @param  array|integer $rule_list  a list of rules            
   *  @return string  the list of rules
   */        
  static function rules($rule_list){
  
    $ret_prefix = '';
    $ret_list = array();
    $ret_str = '';
    
    if($rule_list instanceof parse_date_rule){
      $ret_prefix = 'Rules';
    }else{
      $ret_prefix = '  Rule';
      $rule_list = array($rule_list);
    }//if/else
    
    // get the defined constants...
    $class = new ReflectionClass('parse_date');
    $constants = $class->getConstants();
    ///out::e($constants,$rule_list);
    
    foreach($rule_list as $rule){
    
      if(($key = array_search($rule,$constants,true)) !== false){
      
        $ret_list[] = $key;
      
      }//if
      
    }//foreach
    
    if(!empty($ret_list)){
      $ret_str = sprintf('%s: %s',$ret_prefix,join(', ',$ret_list));
    }//if
    
    return $ret_str;
  
  }//method

  /**
   *  the idea is to normalize the hours, to handle something like 10:00-2:00 so that
   *  it returns the right times   
   *
   *  @param  string  $default_meridian if start has no meridian, then this will become its meridian (am|pm)
   *  @param  object  $token_start  the start parse_date_time_token instance
   *  @param  object  $token_start  the stop parse_date_time_token instance         
   *  @return object|array array($token_start,$token_stop) if $token_stop defined, $token_start if not
   */           
  static function normalizeHours($default_meridian,$token_start,$token_stop = null){
  
    $ret_both = false;
  
    if(empty($token_stop)){
    
      if(!$token_start->hasMeridian()){
        $token_start->meridian($default_meridian);
      }//if
    
    }else{
    
      if(!$token_start->hasMeridian() && !$token_stop->hasMeridian()){
        $token_start->meridian($default_meridian);
      }//if
    
      $ret_both = true;
      $start_hour = $token_start->hour();
      $stop_hour = $token_stop->hour();
      if($stop_hour < $start_hour){
      
        $token_stop->meridian('pm');
      
        if($token_start->isPm()){
        
          if($start_hour < 12){
            $token_stop->setTime(11,59,'pm',($stop_hour * 3600) + 60);
          }//if
        
        }else{
        
          if($start_hour < 12){
            $token_start->meridian('am');
          }else{
            $token_start->meridian('pm');
            $token_stop->setTime(11,59,'pm',($stop_hour * 3600) + 60);
          }//if/else
          
        }//if/else
      
      }else{
      
        if(!$token_start->hasMeridian()){
          $token_start->meridian($token_stop->meridian());
        }else{
          if(!$token_stop->hasMeridian()){
            $token_stop->meridian($token_start->meridian());
          }//if
        }//if/else
        
      }//if/else
      
    }//if
  
    return $ret_both ? array($token_start,$token_stop) : $token_start;
  
  }//method

  /**
   *  increment the month to the next month
   *  
   *  @param  integer $month  1-12
   *  @param  integer $year
   *  @return array array($month,$year)            
   */        
  static function nextMonth($month,$year){
  
    $month++;
    if($month > 12){
    
      $month = $month - 12;
      $year++;
      
    }//if
  
    return array($month,$year);
  
  }//method
  
  /**
   *  increment the day to the next day, the reason why this needs so much info is
   *  because bumping the day by one could move the day to the next month
   *  
   *  @param  integer $year something like 2009
   *  @param  integer $month  1-12
   *  @param  integer $day  1-31
   *  @return array array($year,$month,$day)
   */        
  static function nextDay($year,$month,$day,$tz_offset = 0){
  
    // get days in month for the current month...
    $total_days = self::daysInMonth($month,$year,$tz_offset);
    
    $day++;
    if($day > $total_days){
    
      $day = $day - $total_days;
      list($month,$year) = self::nextMonth($month,$year);
    
    }//if
  
    return array($year,$month,$day);
  
  }//method

  /**
   * returns a timezone offseted datestamp
   * 
   * this class makes sure all dates are calculated from a set timezone, well, the user
   * might have a different timezone so this makes sure any timestamps generated are 
   * correct for the user.         
   *
   * @param integer $tz_offset the timezone offset of the user, defaults to 0
   * @param integer $timestamp a timestamp to calculate the year with, defaults to null
   * @return integer the offset timestamp for the user
   */
  static function timestamp($tz_offset = 0,$timestamp = null){
    if(empty($timestamp)){
      $timestamp = self::$DEFAULT_TIMESTAMP;
      if(empty($timestamp)){ $timestamp = time(); }//if
    }//if
    return $timestamp + $tz_offset;
  }//method
  
  /**
   *  get the UTC offset for the given $tzid
   *  
   *  for a list of supported timezones, see: http://www.php.net/manual/en/timezones.php
   *  
   *  @requires php>5.2.0
   *  
   *  @param  string  $tzid the timezone id, something like 'America/Denver' etc.                  
   *  @return integer the offset, in seconds, of the given timezone id
   */        
  static function offset($tzid){
  
    // sanity...
    if(!class_exists('DateTime')){
      throw new Exception('the DateTime class does not exist, you need php>=5.2.0 to use this function');
    }//if
    if(empty($tzid)){ return 0; }//if

    $ret_int = 0;

    try{

      $dt = new DateTime();
      $dt->setTimezone(new DateTimeZone($tzid));
      $ret_int = $dt->getOffset();
      
    }catch(Exception $e){}//try/catch
  
    return $ret_int;
  
  }//method
  
  /**
   *  returns a 4-digit year
   * 
   *  this function takes a 2 or 4 digit year and returns a 4-digit year, it basically
   *  just makes sure the year returned is always 4 digits   
   *
   *  @param integer $year either a 2 or 4 digit year, defaults to 0, which would make this function return the current year
   *  @param integer $tz_offset the timezone offset of the user, defaults to 0
   *  @param integer $timestamp a timestamp to calculate the year with, defaults to 0
   *  @return integer the year, when all params are 0 it will return the current year
   */
  static function year($year = 0,$tz_offset = 0,$timestamp = 0){
    
    // sanity...
    if(empty($year)){ return date('Y'); }//if
  
    $ret_int = $year;
    if(mb_strlen(strval($year)) === 2){
      $timestamp = empty($timestamp) ? self::timestamp($tz_offset) : $timestamp;
      $full_year = (string)date('Y',$timestamp);
      $ret_int = intval($full_year[0].$full_year[1].$year);
    }//if
    return $ret_int;
  
  }//method
  
  /**
   * Given a month, return how many days are in that month
   *
   * @param integer $month_index the numeric month (1-12)
   * @param integer $year the year, defaults to 0 which means current year
   * @param integer $tz_offset the user's timezone offset from the default class timezone      
   * @return integer the number of days in the month
   */
  static function daysInMonth($month_index,$year = 0,$tz_offset = 0){
    if(($month_index < 1) || ($month_index > 12)){ return 0; }//if
    if(empty($year)){ $year = self::year($year,$tz_offset); }//if
    
    // one line non function calling way (http://us2.php.net/manual/en/function.cal-days-in-month.php#38666)...
    return $month_index == 2 ? ($year % 4 ? 28 : ($year % 100 ? 29 : ($year % 400 ? 28 : 29))) : (($month_index - 1) % 7 % 2 ? 30 : 31);
    /// let this be a lesson to me, there is probably already a function for something like this...
    /// $ret_int = cal_days_in_month(CAL_GREGORIAN,$month_index,2007);
  }//method
  
  /**
   *  pass $input through the validating $regex to see if we got a match
   *  
   *  @param  string  $input  the input to validate
   *  @param  string  $regex  the regex
   *  @return boolean   
   */        
  static function validate($input,$regex){
    // canary...
    if(empty($input) || empty($regex)){ return false; }//if
    $ret_bool = false;
    if(preg_match($regex,$input)){ $ret_bool = true; }//if
    return $ret_bool;
  }//method

}//method

/**
 *  this holds the actual found parts of the date and is used to calculate the timestamps
 *  and get the actual values of the found date.
 *  
 *  this is a private class of {@link parse_date}
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-15-09
 *  @project parse_date    
 ******************************************************************************/   
class parse_date_match extends parse_date_base {

  /**
   *  the starting token of the tokenized text where the date started
   *  @var  integer   
   */     
  private $offset_start_token = null;
  /**
   *  the stopping token of the tokenized text where the date stopped
   *  @var  integer
   */
  private $offset_stop_token = null;
  
  /**
   *  set what text in $input was used to find the given date
   *  
   *  NOTE: uses {@link $offset_start} and {@link $offset_stop} to find the segment that
   *  contained the date, so make sure those are set before calling it      
   *      
   *  @param  string  $input  the input that was tokenized to find the given date         
   */        
  function setText($input){
  
    // canary...
    if(empty($input)){ return; }//if
    
    // make sure we break on a space or EOF...
    $input_len = mb_strlen($input);
    
    $offset_start = empty($this->offset_start_token) ? 0 : $this->offset_start_token->offsetChar();
    $offset_stop = empty($this->offset_stop_token) ? 0 : $this->offset_stop_token->offsetCharStop();
    
    while(($offset_stop < $input_len) && ($offset_stop >= 0)){
      if(ctype_space($input[$offset_stop])){
        break;
      }else{
        $offset_stop++;
      }//if/else
    }//while
    
    $this->val_map['text'] = trim(mb_substr($input,$offset_start,($offset_stop - $offset_start)));
  
  }//method

  function addDay($val){ $this->add('day_list',$val); }//method
  
  function addNum($val){ $this->add('num_list',$val); }//method
  function hasNum(){ return $this->has('num_list'); }//method
  
  /**
   *  saves the index of a day (1 (for Monday) through 7 (for Sunday))
   *  
   *  @param  integer $val  the index to be saved         
   */        
  function addDayIndex($val){ $this->add('day_index_list',$val); }//method
  function addMonth($val){ $this->add('month_list',$val); }//method
  function addYear($val){ $this->add('year_list',$val); }//method
  
  function addTime($val){ $this->add('time_list',$val); }//method
  function hasTime(){ return $this->has('time_list'); }//method
  function time($index = 0){ return isset($this->val_map['time_list'][$index]) ? $this->val_map['time_list'][$index] : null; }//method
  function clearTime(){ $this->clear('time_list'); }//method
  
  function setTimeInterval($val){
    $this->clear('time_interval');
    $this->add('time_interval',$val);
  }//method
  function timeInterval(){ return isset($this->val_map['time_interval'][0]) ? $this->val_map['time_interval'][0] : null; }//method
  
  function addImplied($val){ $this->add('date_implied_list',$val); }//method
  function setPrefix($val){ $this->clear('prefix'); $this->add('prefix',$val); }//method
  function hasPrefix(){ return $this->has('prefix'); }//method
  function prefix($val = null){
    if($val === null){
      return $this->has('prefix') ? $this->val_map['prefix'][0] : null;
    }else{
      $this->setPrefix($val);
    }//if/else  
  }//method
  
  /**
   *  this is here to allow default start and stop times to be set, if you set start and stop normally
   *  using setTimeInterval() for a multi-day date that date would be broken up because it would think
   *  it had    
   *
   */        
  private function addTimeDefault($val){ $this->add('time_default_list',$val); }//method
  
  /**
   *  set to true if the found times shouldn't be split up, by default, if a time is found, say 7pm, 
   *  on a multi-day date then the date is split into every day at 7 (eg, tues-frid at 7pm becomes 4 different
   *  dates (for tuesday, wed, thurs, friday) all starting and stoppin at 7pm, but if you want the found time to
   *  be all encompassing (eg, tues-fri at 7pm starts tuesday at 7pm and stops fri at midnight) then set this to
   *  true
   *  
   *  @param  boolean $val  true if time is a span, false otherwise
   *  @return boolean               
   */
  private function isTimeSpan($val = null){ return $this->val('match_is_time_span',$val,false); }//method
  
  /**
   *  get/set the rule that was matched to create this match instance
   *  @param  parse_date_rule $val  the rule set that was matched
   *  @return parse_date_rule
   */
  function rule($val = null){ return $this->val('match_rule',$val,null); }//method
  function hasRule(){ return $this->has('match_rule'); }//method
  
  /**
   *  this is the private all encompassing add function
   *  
   *  it keeps track of all the offsets so {@link setText()} can function
   *  
   *  @param  string  $key  the index of the val_map
   *  @param  mixed $val  the value to set the key to               
   */        
  private function add($key,$val){
  
    if(!isset($this->val_map[$key])){ $this->val_map[$key] = array(); }//if
    
    if($val instanceof parse_date_token){
      $val_char_offset = $val->offsetChar();
      // ignore negative values because they were auto-generated...
      if($val_char_offset > -1){
        
        if(empty($this->offset_start_token) || ($this->offset_start_token->offsetChar() > $val_char_offset)){
          $this->offset_start_token = $val;
        }//if
        if(empty($this->offset_stop_token) || $this->offset_stop_token->offsetChar() < $val_char_offset){
          $this->offset_stop_token = $val;
        }//if
        
      }//if
    }//if
    
    $this->val_map[$key][] = $val;
  }//method
  
  /**
   *  the public get method, this will decide what kind of date type $this is and
   *  make the right decision to get the start and stop timestamps   
   *
   *  @param  integer $tz_offset  the tz offset to make sure times that are generated are correct
   *  @return array a list of start stop timestamps for the found dates    
   */        
  function get($tz_offset = 0){
  
    $ret_map_list = array();
  
    if(!empty($this->val_map['month_list'])){
    
      $ret_map_list = $this->getMonth($tz_offset);
      
    }else if(!empty($this->val_map['day_index_list'])){
    
      $ret_map_list = $this->getDay($tz_offset);
    
    }else if(!empty($this->val_map['date_implied_list'])){
    
      $ret_map_list = $this->getImplied($tz_offset);
    
    }else if(!empty($this->val_map['time_list']) || !empty($this->val_map['time_interval'])){
  
      $time_map = parse_date_get::dateMap(parse_date_get::timestamp($tz_offset));
      $year_start = $time_map['year'];
      $month_start = $time_map['mon'];
      $day_start = $time_map['mday'];
      
      // find out if ther is a meridian...
      $has_meridian = false;
      $token_time = $this->time();
      if(empty($token_time)){
        $token_time = $this->timeInterval();
        if(!empty($token_time)){
          $has_meridian = $token_time->start()->hasMeridian() || $token_time->stop()->hasMeridian();
        }//if
      }else{
        $has_meridian = $token_time->hasMeridian();
      }//if/else
      
      list($time_start,$time_stop) = $this->getTime();
      if($time_stop[0] < $time_map['hours']){
        
        // if there is a meridian then we just want the next day, if there isn't then we want
        // the next available time block (eg, if we had 7-9 and it was 1pm, then we would want 7-9pm)
        if($has_meridian){
          
          list($year_start,$month_start,$day_start) = parse_date_get::nextDay($year_start,$month_start,$day_start,$tz_offset);
        
        }else{
          
          $time_start[0] = $time_start[0] + 12;
          $time_stop[0] = $time_stop[0] + 12;
          if($time_start[0] > 23){
            $time_start[0] = $time_start[0] % 24;
            $time_stop[0] = $time_stop[0] % 24;
            list($year_start,$month_start,$day_start) = parse_date_get::nextDay($year_start,$month_start,$day_start,$tz_offset);
          }//if
        
        }//if/else
        
      }//method
    
      $ret_map_list = $this->getTimestamps(
        array($year_start,$year_start),
        array($month_start,$month_start),
        array($day_start,$day_start),
        array($time_start,$time_stop)
      );
    
    }//if/else if
    
    return $ret_map_list;
  
  }//method
  
  /**
   *  this will get the timestamps for a date that is implied, things like "this month"
   *  
   *  @param  integer $tz_offset  the tz offset to make sure times that are generated are correct
   *  @return array a list of start stop timestamps for the found dates         
   */
  private function getImplied($tz_offset){
  
    $ret_map_list = array();
    $timestamp = parse_date_get::timestamp($tz_offset);
    $time_map = parse_date_get::dateMap($timestamp);
    $start_i = 0;
    $stop_i = 1;
    $token = $this->val_map['date_implied_list'][$start_i];
    
    // all these need to be set in the ifs...
    $year_start = $year_stop = 0;
    $month_start = $month_stop = 0;
    $day_start = $day_stop = 0;
    
    // if a num is present then assume the date is of type: "in 5 days"
    if($this->hasNum()){
    
      $num = (int)$this->val_map['num_list'][$start_i]->getNumeric();
      $this->clearTime(); // no time's are allowed for these time types
    
      $set_time = false;
      $implied_timestamp = $timestamp;
      $implied_time_map = array();
    
      if($token->isMinute()){
      
        $implied_timestamp += ($num * 60);
        $implied_time_map = parse_date_get::dateMap($implied_timestamp);
        $set_time = true;
      
      }else if($token->isHour()){
      
        $implied_timestamp += ($num * 3600);
        $implied_time_map = parse_date_get::dateMap($implied_timestamp);
        $set_time = true;
      
      }else if($token->isDay()){
      
        $implied_timestamp += ($num * 86400);
        $implied_time_map = parse_date_get::dateMap($implied_timestamp);
      
      }else if($token->isWeek()){
      
        $implied_timestamp += ($num * 604800);
        $implied_time_map = parse_date_get::dateMap($implied_timestamp);
        $implied_start_map = parse_date_get::dateMap($implied_timestamp + (86400 * ((-1 * $implied_time_map['wday']) + 1)));
        $implied_stop_map = parse_date_get::dateMap($implied_timestamp + (86400 * (7 - $implied_time_map['wday'])));
        ///out::e($implied_time_map,$implied_start_map,$implied_stop_map);
        $day_start = $implied_start_map['mday'];
        $day_stop = $implied_stop_map['mday'];
        
      }else if($token->isMonth()){
      
        $implied_timestamp += ($num * 2419200); // 4 weeks in seconds
        $implied_time_map = parse_date_get::dateMap($implied_timestamp);
        $day_start = 1;
        $day_stop = $implied_time_map['dim'];
      
      }//if/else if...
    
      if($set_time){
      
        $time_token = new parse_date_time_token();
        $time_token->setTime($implied_time_map['hours'],$implied_time_map['minutes'],'');
        $time_token->isMil(true);
        $this->addTime($time_token);
    
      }//if
      
      if(empty($year_start)){ $year_start = $year_stop = $implied_time_map['year']; }//if
      if(empty($month_start)){ $month_start = $month_stop = $implied_time_map['mon']; }//if
      if(empty($day_start)){ $day_start = $day_stop = $implied_time_map['mday']; }//if
    
    }else{
    
      $is_next = $this->hasPrefix() ? $this->prefix()->isNext() : false;
      
      if($token->isMonth()){
      
        $month_start = $time_map['mon'];
        $year_start = $time_map['year'];
      
        if($is_next){
        
          list($month_start,$year_start) = parse_date_get::nextMonth($month_start,$year_start);
        
        }//if
        
        $month_stop = $month_start;
        $year_stop = $year_start;
        
        // set day...
        $day_start = isset($this->val_map['day_list'][$start_i]) ? $this->val_map['day_list'][$start_i]->get() : 1;
        $day_stop = isset($this->val_map['day_list'][$stop_i]) 
          ? $this->val_map['day_list'][$stop_i]->get() 
          : (isset($this->val_map['day_list'][$start_i]) ? $day_start : parse_date_get::daysInMonth($month_stop,$year_stop,$tz_offset));
        if($day_start > $day_stop){ $day_stop = $day_start; }//if
      
      }else if($token->isWeek()){
      
        $di_start = 1; // monday
        $duration = 6;
        $today = $time_map['wday'];
        
        $year_start = $time_map['year'];
        $month_start = $time_map['mon'];
        $day_start = $time_map['mday'];
        $total_days = $time_map['dim'];
        
        if(($today > 5) || $is_next){
          
          $day_start = (7 - $today) + $time_map['mday'] + 1;
          if($day_start > $total_days){
            $day_start -= $total_days;
            list($month_start,$year_start) = parse_date_get::nextMonth($month_start,$year_start);
          }//if
        
        }//if
      
        $year_stop = $year_start;
        $month_stop = $month_start;
        $day_stop = $day_start + $duration;
        if($day_stop > $total_days){
          $day_stop -= $total_days;
          list($month_stop,$year_stop) = parse_date_get::nextMonth($month_stop,$year_stop);
        }//if
      
      }//if/else if
      
    }//if/else
  
    if(($year_start + $year_stop + $month_start + $month_stop + $day_start + $day_stop) > 0){
    
      $time_list = $this->getTime();
      $ret_map_list = $this->getTimestamps(
        array($year_start,$year_stop),
        array($month_start,$month_stop),
        array($day_start,$day_stop),
        $time_list
      );
      
    }///if
  
    return $ret_map_list;
  
  }//method
  
  /**
   *  this will get the timestamps for a date that has a designated month
   *  
   *  @param  integer $tz_offset  the tz offset to make sure times that are generated are correct
   *  @return array a list of start stop timestamps for the found dates         
   */        
  private function getMonth($tz_offset){
  
    $ret_map_list = array();
    $timestamp = parse_date_get::timestamp($tz_offset);
    $time_map = parse_date_get::dateMap($timestamp);
    $start_i = 0;
    $stop_i = 1;
    
    $month_start = $this->val_map['month_list'][$start_i]->month();
    $month_stop = isset($this->val_map['month_list'][$stop_i]) ? $this->val_map['month_list'][$stop_i]->month() : $month_start;
    ///if($month_start > $month_stop){ $month_stop = $month_start; }//if
    
    $day_start = isset($this->val_map['day_list'][$start_i]) ? $this->val_map['day_list'][$start_i]->get() : 1;
    
    // get the year, if automatically set, compensate for this month being bigger than the found one...
    $year_start = 0;
    if(isset($this->val_map['year_list'][$start_i])){
    
      $year_start = $this->val_map['year_list'][$start_i]->year();
    
    }else{
    
      $year_start = parse_date_get::year(0,$tz_offset,$timestamp);
      
      // get current month index...
      $month_current = $time_map['mon'];
      if($month_current > $month_start){
        $year_start++;
      }else if($month_current == $month_start){
        $day_current = $time_map['mday'];
        if($day_current > $day_start){
          $year_start++;
        }//if
      }//if/else
      
    }//if/else  
    
    $year_stop = isset($this->val_map['year_list'][$stop_i]) 
      ? $this->val_map['year_list'][$stop_i]->year()
      : $year_start;
      
    if($year_start > $year_stop){ $year_stop = $year_start; }//if
    
    // compensate for cross year dates (eg, december 3 - january 5)...
    if($month_start > $month_stop){ $year_stop++; }//if
    
    $day_stop = isset($this->val_map['day_list'][$stop_i]) 
      ? $this->val_map['day_list'][$stop_i]->get() 
      : (isset($this->val_map['day_list'][$start_i]) ? $day_start : parse_date_get::daysInMonth($month_stop,$year_stop,$tz_offset));
    if(($month_start == $month_stop) && ($day_start > $day_stop)){ $day_stop = $day_start; }//if
    
    ///out::e($month_start,$month_stop,$year_start,$year_stop,$day_start,$day_stop);
    
    $time_list = $this->getTime();
    
    $ret_map_list = $this->getTimestamps(
      array($year_start,$year_stop),
      array($month_start,$month_stop),
      array($day_start,$day_stop),
      $time_list
    );
  
    ///out::e($ret_map_list,$time_list);
  
    return $ret_map_list;
  
  }//method
  
  /**
   *  put together dates for a date like "next tuesday"
   *  
   *  @param  integer $tz_offset  the tz offset to make sure times that are generated are correct
   *  @return array a list of start stop timestamps for the found dates        
   */        
  private function getDay($tz_offset){

    $ret_map_list = array();
    $timestamp = parse_date_get::timestamp($tz_offset);
    $time_map = parse_date_get::dateMap($timestamp);
    $today = $time_map['wday'];
    $start_i = 0;
    $stop_i = 1;
    
    $duration = 0;
    $start_timestamp = $stop_timestamp = 0;
    
    if(!empty($this->val_map['day_list'][$start_i])){
    
      // handle day text like: "monday the 15th" by using the 15th as the date trigger
    
      $day_start = $this->val_map['day_list'][$start_i]->get();
      if($day_start < $time_map['mday']){
      
        list($month,$year) = parse_date_get::nextMonth($time_map['mon'],$time_map['year']);
        
      }else{
      
        $month = $time_map['mon'];
        $year = $time_map['year'];
      
      }//if/else
      
      $start_timestamp = mktime(0,0,0,$month,$day_start,$year);
      $keyword = $this->val_map['day_list'][$start_i]->keyword();
      
    }else{
    
      // handle day text like: next monday
    
      $day_start = $this->val_map['day_index_list'][$start_i]->wday();
      $day_stop = isset($this->val_map['day_index_list'][$stop_i])
        ? $this->val_map['day_index_list'][$stop_i]->wday()
        : 0;
  
      // find out how many days the duration actually lasts...  
      if(!empty($day_stop)){
  
        if($day_stop == $day_start){
          $duration = 7;
        }else if($day_stop > $day_start){
          $duration = ($day_stop - $day_start);
        }else if($day_stop < $day_start){
          $duration = ((7 - $day_start) + $day_stop);
        }//if
        
      }else{
      
        $day_stop = $day_start;
      
      }//if/else
  
      $keyword = $this->val_map['day_index_list'][$start_i]->keyword();
  
      // now get the actual timestamps...
      ///if($today <= $day_start){
      if($keyword->isSubType(1) || (!$this->hasPrefix() && ($today <= $day_start)) || ($today < $day_start)){ /* @todo, we have a problem here, because just < works for "next thurs" when the day
        is actually thursday by jumping ahead, the problem is it also jumps "today", so I need to put a check in right
        here */ 
        // since the day we are looking for hasn't been reached in the week yet, just find how many days away it is...
        $start_timestamp = $timestamp + (($day_start - $today) * parse_date_get::DAY);
      }else{
        // the day we are looking for has already happened this week, so this is referring to the day
        // for next week...
        $start_timestamp = $timestamp + (((7 - $today) + $day_start) * parse_date_get::DAY);
        ///out::e($start_timestamp);
      }//if/else
      
    }//if/else
    
    $start_time_map = parse_date_get::dateMap($start_timestamp);
    
    if($keyword->hasTimeStart()){
    
      $keyword_start = $keyword->timeStart();
      $start_time_hour = (int)$start_time_map['hours'];
      $start_keyword_hour = (int)$keyword_start['hour'];
    
      if(!$keyword->isSubType(1)){
      
        // see if we are currently in the time, bump it to the next day if we are...  
        if($start_time_hour > $start_keyword_hour){
          $start_timestamp += parse_date_get::DAY;
          $start_time_map = parse_date_get::dateMap($start_timestamp);
        }else if($start_time_hour > $start_keyword_hour){
          if((int)$start_time_map['minutes'] >= (int)$keyword_start['minute']){
            $start_timestamp += parse_date_get::DAY;
            $start_time_map = parse_date_get::dateMap($start_timestamp);
          }//if
        }//if/else if
        
      }//if
    
      if(empty($this->val_map['time_list']) && empty($this->val_map['time_interval'])){
      
        // if we don't have a duration set the start and stop times, otherwise set the start
        // time but let the end time (midnight on the stop day, this is to allow stuff like "from tonight until tuesday"
        if(empty($duration)){
        
          $time_interval_token = new parse_date_time_interval_token();
        
          $start_time_token = new parse_date_time_token();
          $start_time_token->setTime(
            $keyword_start['hour'],
            $keyword_start['minute'],
            $keyword_start['meridian'],
            $keyword_start['increment']
          );
          $start_time_token->isMil(true);
          $start_time_token->keyword($keyword);
          $time_interval_token->start($start_time_token);
          
          $keyword_stop = $keyword->timeStop();
          $stop_time_token = new parse_date_time_token();
          $stop_time_token->setTime(
            $keyword_stop['hour'],
            $keyword_stop['minute'],
            $keyword_stop['meridian'],
            $keyword_stop['increment']
          );
          $stop_time_token->isMil(true);
          $stop_time_token->keyword($keyword);
          $time_interval_token->stop($stop_time_token);
          $time_interval_token->keyword($keyword);
          
          $this->setTimeInterval($time_interval_token);
          
        }else{
          
          $start_token = new parse_date_time_token();
          $start_token->setTime($keyword_start['hour'],$keyword_start['minute'],$keyword_start['meridian']);
          $start_token->keyword($keyword);
          $start_token->isMil(true);
          $this->addTimeDefault($start_token);
          
        }//if/else
      
      }//if
    
    }//if
    
    if($keyword->hasDuration()){
      $duration = $keyword->duration();
    }//if
    
    $stop_timestamp = $start_timestamp;
    if(!empty($duration)){
      $stop_timestamp = ($start_timestamp + ($duration * parse_date_get::DAY));
    }//if
    $stop_time_map = parse_date_get::dateMap($stop_timestamp);
    
    $year_start = $start_time_map['year'];
    $year_stop = $stop_time_map['year'];
    $month_start = $start_time_map['mon'];
    $month_stop = $stop_time_map['mon'];
    $day_start = $start_time_map['mday'];
    $day_stop = $stop_time_map['mday'];
    
    $time_list = $this->getTime($keyword->meridianDefault());
    
    $ret_map_list = $this->getTimestamps(
      array($year_start,$year_stop),
      array($month_start,$month_stop),
      array($day_start,$day_stop),
      $time_list
    );
    
    return $ret_map_list;
    
  }//method
  
  /**
   *  gets the time info for the date match
   *  
   *  this method makes use of {@link addTime()}, {@link setTimeInterval()}, and {@link addTimeDefault()} to find the time
   *  if it is a time, then start and stop are set to the same thing, if time is an interval then start and stop are set
   *  to the interval's start and stop, if a time can't be found then default is used, if default is time then just start
   *  is set and stop is 23:59+60, if default is an interval then start and stop are set. Any found default results in 
   *  {@link isTimeSpan()} being set to true, by default time returns start: 0:0:0+0 and stop: 23:59:0+60   
   *  
   *  @param  string  $default_meridian what meridian (am|pm) should be used when time is normalized   
   *  @return array array(0 => array($start_hour,$start_minute,$start_second,$start_increment)[,1 => array($stop_hour,$stop_minute,$stop_second,$stop_increment)])
   */        
  private function getTime($default_meridian = ''){
  
    $ret_list = array();
    $token_start_time = $token_stop_time = null;
    
    if(!empty($this->val_map['time_list'])){

      $token_start_time = $this->val_map['time_list'][0];
      $token_start_time = parse_date_get::normalizeHours($default_meridian,$token_start_time);
      $token_stop_time = $token_start_time;
    
    }else if(!empty($this->val_map['time_interval'][0])){

      $token_time = $this->val_map['time_interval'][0];
      $token_start_time = $token_time->start();
      $token_stop_time = $token_time->stop();
      
      list($token_start_time,$token_stop_time) = parse_date_get::normalizeHours($default_meridian,$token_start_time,$token_stop_time);
    
    }else{

      $this->isTimeSpan(true);
    
      if(isset($this->val_map['time_default_list'])){
        
        if($this->val_map['time_default_list'][0] instanceof parse_date_time_token){
          $token_start_time = $this->val_map['time_default_list'][0];
        }else if($this->val_map['time_default_list'][0] instanceof parse_date_time_interval_token){
          $token_start_time = $this->val_map['time_default_list'][0]->start();
          $token_stop_time = $this->val_map['time_default_list'][0]->stop();
        }//if/else if
    
      }//if/else
    
    }//if/else if/else
    
    if(empty($token_start_time)){
      $ret_list[] = array(0,0,0,0);
    }else{
      $ret_list[] = array(
        $token_start_time->hourMil(),
        $token_start_time->minute(),
        $token_start_time->keyword()->getTypeSecond(),
        $token_start_time->increment()
      );
    }//if/else
    
    if(empty($token_stop_time)){
      $ret_list[] = array(23,59,0,60);
    }else{
      $ret_list[] = array(
        $token_stop_time->hourMil(),
        $token_stop_time->minute(),
        $token_stop_time->keyword()->getTypeSecond(),
        $token_stop_time->increment()
      );
    }//if/else
  
    ///out::e($ret_list);
    return $ret_list;
  
  }//method
  
  /**
   *  checks the time and puts together the list with completed timestamps
   *  
   *  @param  array $year_list  array($start,$stop)
   *  @param  array $month_list array($start,$stop)
   *  @param  array $day_list   array($start,$stop)
   *  @param  array $time_list  array(array($start_hour,$start_minute),array($stop_hour,$stop_minute))   
   *  @return array a list of start stop timestamps
   */        
  private function getTimestamps($year_list,$month_list,$day_list,$time_list){
  
    $ret_map_list = array();
    
    if($this->isTimeSpan()){
    
      $ret_map_list[] = $this->getMap(
        $year_list,
        $month_list,
        $day_list,
        $time_list
      );
      
    }else{
    
      list($year_start,$year_stop) = $year_list;
      list($month_start,$month_stop) = $month_list;
      list($day_start,$day_stop) = $day_list;
      
      $day_start_list = $day_stop_list = array();
      if($month_stop != $month_start){
      
        $day_start_list = range($day_start,parse_date_get::daysInMonth($month_start,$year_start),1);
        $day_stop_list = range(1,$day_stop,1);
      
      }else{
      
        $day_start_list = range($day_start,$day_stop,1);
      
      }//if/else
      
      foreach($day_start_list as $day){
      
        $ret_map_list[] = $this->getMap(
          array($year_start,$year_start),
          array($month_start,$month_start),
          array($day,$day),
          $time_list
        );
  
      }//foreach
      
      foreach($day_stop_list as $day){
      
        $ret_map_list[] = $this->getMap(
          array($year_stop,$year_stop),
          array($month_stop,$month_stop),
          array($day,$day),
          $time_list
        );
  
      }//foreach
      
    }//if
    
    return $ret_map_list;
      
  }//method
  
  /**
   *  called from {@link getTimestamps()} and is responsible for putting the timestamps together
   *  
   *  @param  array $year_list  array($start,$stop)
   *  @param  array $month_list array($start,$stop)
   *  @param  array $day_list   array($start,$stop)
   *  @param  array $time_list  array(array($start_hour,$start_minute),array($stop_hour,$stop_minute))        
   *  @return object  a parse_date_map instance
   */           
  private function getMap($year_list,$month_list,$day_list,$time_list){
  
    list($year_start,$year_stop) = $year_list;
    list($month_start,$month_stop) = $month_list;
    list($day_start,$day_stop) = $day_list;
    list($time_start,$time_stop) = $time_list;
    
    ///out::e($time_start[0],$time_start[1],$month_start,$day_start,$year_start,$time_start[2]);
    ///out::e('',$time_stop[0],$time_stop[1],$month_stop,$day_stop,$year_stop,$time_stop[2]);
    
    $start_timestamp = mktime($time_start[0],$time_start[1],$time_start[2],$month_start,$day_start,$year_start) + $time_start[3];
    $stop_timestamp = mktime($time_stop[0],$time_stop[1],$time_stop[2],$month_stop,$day_stop,$year_stop) + $time_stop[3];
    
    // safety check...
    if($stop_timestamp < $start_timestamp){
      $stop_timestamp = $start_timestamp;
    }//if
    
    $ret_map = new parse_date_map();
    $ret_map->start($start_timestamp);
    $ret_map->stop($stop_timestamp);
    $ret_map->text($this->val_map['text']);
    
    $recur_type = parse_date::RECUR_NONE;
    if($this->hasPrefix() && $this->prefix()->isRecurring()){
      if($this->hasRule()){
        $recur_type = $this->rule()->recur();
      }//if
    }//if
    $ret_map->recur($recur_type);
    
    
    return $ret_map;
  
  }//method

}//class

/**
 *  this is one of the only classes that is external to the parse_date class, parse_date::find()
 *  returns a list of instances of this class 
 *  
 *  this is a private class of {@link parse_date}
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-18-09
 *  @project parse_date    
 ******************************************************************************/ 
class parse_date_map extends parse_date_base {
  
  /**
   *  get/set the recur type
   *  
   *  @since  10-27-09   
   *  @param  integer $val  the recur type      
   *  @return integer
   */
  function recur($val = null){ return $this->val('recur_type',$val,parse_date::RECUR_NONE); }//method
  
  /**
   *  get/set the start timestamp
   *  
   *  @param  integer $val  the start timestamp, if null then this function will return the currently set start timestamp      
   *  @return integer
   */
  function start($val = null){ return $this->val('start_timestamp',$val,0); }//method
 
  /**
   *  true if there is a start timestamp greater than 0
   *  @return boolean   
   */
  function hasStart(){ return $this->has('start_timestamp'); }//method
  
  /**
   *  get/set the stop timestamp
   *  
   *  @param  integer $val  the stop timestamp, if null then this function will return the currently set stop timestamp      
   *  @return integer
   */
  function stop($val = null){ return $this->val('stop_timestamp',$val,0); }//method
  
  /**
   *  true if there is a stop timestamp greater than 0
   *  @return boolean   
   */
  function hasStop(){ return $this->has('stop_timestamp'); }//method
  
  /**
   *  return true if this map contains a valid start and stop timestamp
   *  @return boolean
   */
  function isValid(){ return ($this->hasStart() && $this->hasStop()); }//method
  
  /**
   *  get/set the found text
   *  
   *  the found text is the text that was used to generate the start and stop timestamps      
   *  
   *  @param  string  $val  if null, then return the currently set text, otherwise set text to $val
   *  @return string
   */       
  function text($val = null){ return $this->val('text',$val,0); }//method
  
}//class

/**
 *  this class handles a rule set, it lets stuff be added to a rule that wasn't
 *  possible with just array rule sets 
 *  
 *  this is a private class of {@link parse_date}
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-27-09
 *  @project parse_date    
 ******************************************************************************/
class parse_date_rule extends parse_date_base implements ArrayAccess,IteratorAggregate {

  /**
   *  create a new rule set
   *  
   *  @param  $args,... as many rules as you want, rules are integers      
   */
  function __construct(){
  
    $args = func_get_args();
    $this->val('rule_list',$args,array());

  }//method

  /**
   *  get/set the rule's recurring potential
   *  
   *  @param  integer $val  one of parse_date_map's RECURE_TYPE_* constannts
   *  @return integer the rule's currently set recurring type
   */
  function recur($val = null){ return $this->val('recur_type',$val,parse_date::RECUR_NONE); }//method
  
  /**
   *  Required definition of interface IteratorAggregate
   *  
   *  @link http://www.php.net/manual/en/class.iteratoraggregate.php     
   *      
   *  @return ArrayIterator that will go through the rule_list for this rule  
   */
  function getIterator(){
    return new ArrayIterator($this->val('rule_list',null,array()));
  }//method
  
  /**#@+
   *  Required definition of interface ArrayAccess
   *  @link http://www.php.net/manual/en/class.arrayaccess.php   
   */
  /**
   *  Set a value given it's key e.g. $A['title'] = 'foo';
   */
  function offsetSet($key,$val){
    $rule_list = $this->val('rule_list',null,array());
    $rule_list[$key] = $val;
    $this->val('rule_list',$rule_list,array());
  }//method
  /**
   *  Return a value given it's key e.g. echo $A['title'];
   */
  function offsetGet($key){
    $rule_list = $this->val('rule_list',null,array());
    return isset($rule_list[$key]) ? $rule_list[$key] : null;
  }//method
  /**
   *  Unset a value by it's key e.g. unset($A['title']);
   */
  function offsetUnset($key){
    $rule_list = $this->val('rule_list',null,array());
    if(isset($rule_list[$key])){ unset($rule_list[$key]); }//if
  }//method
  /**
   *  Check value exists, given it's key e.g. isset($A['title'])
   */
  function offsetExists($key){
    $rule_list = $this->val('rule_list',null,array());
    return isset($rule_list[$key]);
  }//method
  /**#@-*/
  
}//method

/**
 *  this is one of the only classes that is external to the parse_date class, parse_date::findInTimestamp()
 *  returns an instances of this class 
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-28-09
 *  @project parse_date    
 ******************************************************************************/
class parse_date_info_map extends parse_date_base {

  function __construct($start_timestamp,$stop_timestamp,$current_timestamp){
  
    $this->start(parse_date_get::dateMap($start_timestamp));
    $this->stop(parse_date_get::dateMap($stop_timestamp));
    $this->current(parse_date_get::dateMap($current_timestamp));
  
  }//method
  
  private function start($val = null){ return $this->val('start_time_info',$val,array()); }//method
  private function stop($val = null){ return $this->val('stop_time_info',$val,array()); }//method
  private function current($val = null){ return $this->val('current_time_info',$val,array()); }//method

  function getTimestampStart(){ $map = $this->start(); return $map[0]; }//method
  function getTimestampCurrent(){ $map = $this->current(); return $map[0]; }//method
  function getTimestampStop(){ $map = $this->stop(); return $map[0]; }//method

  /**
   *  gets the ISO 8601 dates for the internal start and stop stimes
   *  
   *  @todo flesh this out so that the dates are less exact (eg, if there is a day span, the times aren't needed)
   *      
   *  @link http://en.wikipedia.org/wiki/ISO_8601
   *  @link http://www.iso.org/iso/date_and_time_format   
   *  @since  1-14-11 by Jay
   *  @return array array($start_str,$stop_str);
   */
  public function getISO8601(){
  
    $start_str = date(DateTime::ISO8601,$this->getTimestampStart());
    $stop_str = '';
    
    if($this->isInterval()){
      $stop_str = date(DateTime::ISO8601,$this->bestTimestampStop());
    
    }//if
    
    // @todo  might want to reformat as duration: http://en.wikipedia.org/wiki/ISO_8601#Durations
    return array($start_str,$stop_str);
  
  }//method

  /**
   *  sometimes, the stop timestamp is a day (or week, or month) boundary, meaning it
   *  actually ends at midnight on the next day (eg, if stop is end of day on 26th of the month, the
   *  timestamp is the 27th of the month at midnight) so this checks that and reverts the stop
   *  timestamp to 11:59:59 on the day it was meant to be stopped.
   *  
   *  @return integer a normalized stop timestamp
   */
  function bestTimestampStop(){
    
    $map = $this->stop();
    $ret_int = $map[0];
    
    // see if $map is midnight on whatever day...
    if($this->isMidnight($map)){
    
      // go to 11:59:59 on the previous day...
      $ret_int = $map[0] - ($map['seconds'] + 1);
    
    }//if
    
    return $ret_int;
    
  }//method

  /**
   *  return true if $start_map and $stop_map's duration is shorter than one day
   *      
   *  @return boolean   
   */
  function isSameDay(){
  
    $ret_bool = false;
    $start_map = $this->start();
    $stop_map = $this->stop();
    
    // these are what's going to be compared...
    $start_year = $start_map['year'];
    $start_month = $start_map['mon'];
    $start_day = $start_map['mday'];
    
    if($start_day !== $stop_map['mday']){
      
      if($this->isMidnight($stop_map)){
      
        list($start_year,$start_month,$start_day) = parse_date_get::nextDay(
          $start_map['year'],
          $start_map['mon'],
          $start_map['mday']
        );
      
      }//if
      
    }//if
  
    return (($start_month == $stop_map['mon'])
      && ($start_day == $stop_map['mday'])
      && ($start_year == $stop_map['year']));
    
  }//method
  
  function isStartOnTheHour(){ return $this->isOnTheHour($this->start()); }//method
  function isStopOnTheHour(){ return $this->isOnTheHour($this->stop()); }//method
  
  /**
   *  return true if the $map's minutes are empty, meaning the map starts on an hour
   *  like 1:00, 2:00, etx.
   *  
   *  @param  array $map
   *  @return boolean
   */
  private function isOnTheHour($map){
    return empty($map['minutes']);
  }//method
  
  function isStartMidnight(){ return $this->isMidnight($this->start()); }//method
  function isStopMidnight(){ return $this->isMidnight($this->stop()); }//method
  
  private function isMidnight($map){
    return empty($map['hours']) && empty($map['minutes']);
  }//method
  
  /**
   *  return true if $start_map and $stop_map's duration is one day exactly
   *  
   *  @return boolean   
   */
  function isAllDay(){
  
    $ret_bool = false;
    $start_map = $this->start();
    $stop_map = $this->stop();
    
    if($this->isMidnight($start_map) && $this->isMidnight($stop_map)){
    
      list($start_year,$start_month,$start_day) = parse_date_get::nextDay(
        $start_map['year'],
        $start_map['mon'],
        $start_map['mday']
      );
      
      $ret_bool = ($start_year == $stop_map['year'])
        && ($start_month == $stop_map['mon'])
        && ($start_day == $stop_map['mday']);
    
    }//if

    return $ret_bool;
      
  }//method
  
  /**
   *  return true if $start_map and $stop_map's duration is one month exactly
   *  
   *  @return boolean   
   */
  function isAllMonth(){
  
    $start_map = $this->start();
    $stop_map = $this->stop();
    
    $ret_bool = false;
    if($start_map['mday'] == 1){
      
      if($start_map['mon'] == $stop_map['mon']){
        $ret_bool = ($stop_map['mday'] == $start_map['dim'])
          && ($start_map['year'] == $stop_map['year']);
      }else{
      
        if($this->isMidnight($stop_map)){
      
          list($start_month,$start_year) = parse_date_get::nextMonth($start_map['mon'],$start_map['year']);
          $ret_bool = ($start_month == $stop_map['mon'])
            && ($start_year == $stop_map['year']);
          
        }//if
      
      }//if/else if
      
    }//if/else

    return $ret_bool;

  }//method
  
  /**
   *  return true if $start_map and $stop_map's duration is more than one day
   *  
   *  @return boolean   
   */
  function isMultiDay(){
  
    // canary...
    if($this->isAllMonth()){ return true; }//if
  
    $ret_bool = false;
    $start_map = $this->start();
    $stop_map = $this->stop();

    // if they are the same month, they have to be different days...
    if($this->isSameMonth()){
    
      $ret_bool = ($start_map['mday'] != $stop_map['mday']);
      
    }else{
    
      // since they are different months, it is multi-day...
      $ret_bool = true;
    
    }//if/else
    
    return $ret_bool;

  }//method
  
  /**
   *  return true if $start_map and $stop_map have the same month
   *  
   *  @return boolean   
   */
  function isSameMonth(){
  
    $ret_bool = false;
    $start_map = $this->start();
    $stop_map = $this->stop();
    
    if($this->isSameYear()){
      $ret_bool = ($start_map['mon'] == $stop_map['mon']);
    }//if
    
    if(!$ret_bool){
      if(($start_map['mday'] === 1) && $this->isMidnight($stop_map)){
      
        $start_month = $start_map['mon'];
        $start_year = $start_map['year'];
      
        list($start_month,$start_year) = parse_date_get::nextMonth($start_map['mon'],$start_map['year']);  
        $ret_bool = ($start_month == $stop_map['mon']) && ($start_year == $stop_map['year']);
       
      }//if
    }//if
    
    return $ret_bool;
  
  }//method
  
  /**
   *  return true if $start_map and $stop_map have the same year
   *  
   *  @return boolean   
   */
  function isSameYear(){
    $start_map = $this->start();
    $stop_map = $this->stop();
    $ret_bool = false;
    
    if($start_map['year'] == $stop_map['year']){
    
      $ret_bool = true;
    
    }else{
    
      if(($stop_map['mon'] == 1) && ($stop_map['mday'] == 1) && $this->isMidnight($stop_map)){
      
        $ret_bool = ($start_map['year'] + 1) == $stop_map['year'];
      
      }//if
    
    }//if/else
    
    
    return $ret_bool;
  }//method
  
  /**
   *  return true if $start_map and $current_map have the same year
   *  
   *  @return boolean   
   */
  function isCurrentYear(){
    $start_map = $this->start();
    $current_map = $this->current();
    return ($start_map['year'] == $current_map['year']);
  }//method

  /**
   *  gets the type second that is embedded into the timestamps, see {@link parse_date_tokens::setKeywords()} for
   *  an explanation of what a type second is   
   *  
   *  a type second only exists if both start and stop timestamps have the same second value
   *  
   *  @return integer       
   */
  private function getTypeSecond(){
  
    $ret_int = 0;
    $start_map = $this->start();
    $stop_map = $this->stop();
    
    if(!empty($start_map['seconds']) && !empty($stop_map['seconds'])){
      if($start_map['seconds'] == $stop_map['seconds']){
        $ret_int = $start_map['seconds'];
      }//if
    }//if
    
    return $ret_int;
  
  }//method
  
  /**
   *  true if start and stop are different
   *  
   *  @return boolean      
   */
  function isInterval(){
    $start_map = $this->start();
    $stop_map = $this->stop();
    $interval = $stop_map[0] - $start_map[0];
    return !empty($interval);
  }//method
  
  /**
   *  true if the difference between start and stop is within the given interval
   *  
   *  @param  integer $interval the time, eg, if $interval=3600, stop - start would have to be <= 3600
   *  @return boolean
   */
  function isWithin($interval){
  
    $start_map = $this->start();
    $stop_map = $this->stop();
    $this_interval = $stop_map[0] - $start_map[0];
    return ($this_interval <= $interval);
  
  }//method
  
  /**
   *  true if the instances start and stop encompass the passed in $start and $stop, ie, the instance's
   *  duration exceeds the duration of $start and $stop
   *  
   *  @param  integer $start  unix timestamp
   *  @param  integer $stop unix timestamp
   *  @return boolean
   */
  function isAll($start,$stop)
  {
    return ($this->getTimestampStart() <= $start) && ($this->getTimestampStop() >= $stop);
  }//method
  
  /**
   *  output a nicely formatted time for the {@link start()} and {@link stop()} dates
   *  of this instance
   *  
   *  @return string
   */
  function out(){
  
    $ret_str = $start_date_str = $stop_date_str = $time_str = '';
  
    if($this->isAllDay()){

      $start_date_str = 'j F';
    
    }else if($this->isSameDay()){

      $start_date_str = 'j F';
      $time_str = $this->outTime();
    
    }else if($this->isMultiDay()){
    
      if($this->isAllMonth()){
    
        $start_date_str = 'F';
    
      }else if($this->isSameMonth()){
      
        $start_date_str = 'j';
        $stop_date_str = 'j F';
      
      }else{
      
        $start_date_str = 'j F ';
        $stop_date_str = ' j F';
      
      }//if/else
      
      // even if the interval is multi-day, it might be something like: tues 10pm - 4am,
      // so it could still have a time...
      $time_str = $this->outTime();
      
    }else{
    
      $start_date_str = 'j F ';
      $stop_date_str = ' j F';
      $time_str = $this->outTime();
    
    }//if/else if.../else
    
    if(!$this->isSameYear() && !empty($stop_date_str)){
      
      $start_date_str .= ' Y';
      $stop_date_str .= ' Y';
      
    }else{
      
      if(!$this->isCurrentYear()){
        if(empty($stop_date_str)){
          $start_date_str .= ' Y';
        }else{
          $stop_date_str .= ' Y';
        }//if/else
      }//if
    
    }//if/else
    
    $ret_str = date($start_date_str,$this->getTimestampStart());
    if(!empty($stop_date_str)){
      $ret_str = sprintf('%s-%s',$ret_str,date($stop_date_str,$this->bestTimestampStop()));
    }//if
    if(!empty($time_str)){
      $ret_str = sprintf('%s %s',$ret_str,$time_str);
    }//if
  
    return $ret_str;
  
  }//method
  
  function outTime(){
  
    // canary, there is only a time string if the start and stop occur same day...
    if($this->isAllDay() || (!$this->isSameDay() && !$this->isWithin(parse_date_get::DAY))){ return ''; }//if
    
    $ret_str = '';
    
    if($this->exists(__METHOD__)){
    
      $ret_str = $this->val(__METHOD__,null,'');
      
    }else{
    
      // try to get a time keyword...
      if($this->isGeneralTime()){
        $ret_str = $this->generalTime();
      }//if
      
      if(empty($ret_str)){
    
        $start_map = $this->start();
        $stop_map = $this->stop();
        
        $interval = $stop_map[0] - $start_map[0];
      
        $same_meridian = (($start_map['hours'] < 12) && ($stop_map['hours'] < 12)) 
          || (($start_map['hours'] > 12) && ($stop_map['hours'] > 12));
        
        $start_time_str = array('g');
        ///if(!empty($start_map['minutes'])){ $start_time_str[] = ':i'; }//if
        if(empty($interval) || !$this->isStartOnTheHour()){ $start_time_str[] = ':i'; }//if
        
        if(!$same_meridian || empty($interval)){ $start_time_str[] = 'a'; }//if
        $start_time_str = join('',$start_time_str);
        ///$stop_time_str = empty($stop_map['minutes']) ? 'ga' : 'g:ia';
        $stop_time_str = 'g:ia';
        
        $ret_str = empty($interval)
          ? date($start_time_str,$start_map[0])
          : sprintf('%s-%s',date($start_time_str,$start_map[0]),date($stop_time_str,$stop_map[0]));
      
      }//if/else
      
      $this->val(__METHOD__,$ret_str,'');
    
    }//if/else
    
    return $ret_str;
  
  }//method
  
  /**
   *  return true if time is something like "evening" instead of exact, like "7pm"
   *  
   *  @return boolean      
   */
  function isGeneralTime(){
  
    // canary...
    if($this->existsGeneralTime()){ return $this->hasGeneralTime(); }//if
  
    $ret_bool = false;
  
    // try to get a time keyword...
    if($type_second = $this->getTypeSecond()){
    
      $tokens = new parse_date_tokens();
      $keywords = $tokens->getKeywords();
      if($keyword = $keywords->getTypeTime($type_second)){
      
        $ret_bool = true;
        $this->generalTime($keyword->timeValue());
      
      }//if
    
    }//if
  
    return $ret_bool;
  
  }//method
  
  private function generalTime($val = null){ return $this->val('time_info_gen_time',$val,''); }//method
  private function hasGeneralTime(){ return $this->has('time_info_gen_time'); }//method
  private function existsGeneralTime(){ return $this->exists('time_info_gen_time'); }//method
  
}//class

/**
 *  hold a keyword list, set in {@link parse_date_tokens::setKeywords()} 
 *  
 *  this is a private class of {@link parse_date}
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-29-09
 *  @project parse_date    
 ******************************************************************************/
class parse_date_keywords extends parse_date_base {

  /**
   *  adds a keyword to the keywords list
   *
   *  @param  parse_date_keyword  a keyword instance
   *  #return boolean true if successfully added the keyword   
   */
  function add(parse_date_keyword $keyword){
    
    $ret_bool = false;
    
    if($key = $keyword->name()){
      
      $this->val($key,$keyword,null);
      $ret_bool = true;
    
    }//if
    
    return $ret_bool;
  
  }//method
  
  function match($key){ return $this->has($key); }//method
  function get($key){ return $this->val($key,null,null); }//method
  
  /**
   *  return the keyword that matches the typeTime
   *  
   *  @param  integer $type the time type being sought
   *  @return parse_date_keyword  the matching keyword if one is found, null otherwise         
   */
  function getTypeTime($type){

    $ret_keyword = null;
  
    // see if we can find a matching type time...
    foreach($this->val_map as $keyword){
      if($keyword->isTypeTime($type)){
        return $keyword;
      }//if
    }//foreach
  
    return null;
  
  }//method

}//class

/**
 *  hold a keyword, set in {@link parse_date_tokens::setKeywords()} 
 *  
 *  this is a private class of {@link parse_date} and holds all the meta information
 *  sorrounding a trigger keyword 
 *  
 *  @version 0.1
 *  @author Jay Marcyes {@link http://marcyes.com}
 *  @since 9-29-09
 *  @project parse_date    
 ******************************************************************************/ 
class parse_date_keyword extends parse_date_base {

  function __construct($name = '',$value = '',$type = -1){
  
    $this->name($name);
    $this->value($value);
    $this->type($type);
  
  }//construct
  
  function name($val = null){ return $this->val('keyword_name',$val,''); }//method
  
  function value($val = null){ return $this->val('keyword_value',$val,''); }//method
  
  function isValue($val){
    
    $ret_bool = false;
    
    if($this->exists('keyword_value')){
      
      if(empty($val)){
      
        // both values were empty...
        if(!$this->has('keyword_value')){
          $ret_bool = true;
        }//if
      
      }else{

        $ret_bool = ($this->value() == $val);
      
      }//if/else
    
    }//if

    return $ret_bool;
    
  }//method
  
  
  function type($val = null){ return $this->val('keyword_type',$val,0); }//method
  
  function isType($type){
    $instance_type = (int)$this->type();
    $type = (int)$type;
    return ($instance_type === $type);
  }//method
  
  /**
   *  set a sub type, where type is usually something all encompassing like DAY, or MONTH
   *  the sub type can be something more fine grained (eg, today and tuesday are both DAY types
   *  but today might need to be handled differently than tuesday, so it can have a different
   *  subtype.
   *  
   *  @param  integer $val
   *  @return integer
   */
  function subType($val = null){ return $this->val('keyword_sub_type',$val,0); }//method
  
  function isSubType($type){
    $instance_sub_type = (int)$this->subType();
    $type = (int)$type;
    return ($instance_sub_type === $type);
  }//method
  
  /**
   *  the time type of the keyword, this is different than the keyword's {@link type()}
   *  because it belongs specifically to time
   *  
   *  @param  integer $val  between 0-59
   *  @return integer the currently set type
   */
  function typeTime($val = null){ return $this->typeSecond('keyword_type_time',$val); }//method
  
  function isTypeTime($type){
    $instance_type = (int)$this->typeTime();
    return empty($instance_type) ? false : ($instance_type === (int)$type);
  }//method
  
  /**
   *  get/set how many days the keyword's duration is
   *  
   *  @param  integer $val  how many days
   *  @return integer the duration         
   */
  function duration($val = null){ return $this->val('keyword_duration',$val,0); }//method
  function hasDuration(){ return $this->has('keyword_duration'); }//method
  
  /**
   *  get/set the preferred meridian for the given keyword
   *
   *  @param  string  $val  the meridian (am or pm) that the keyword will prefer if no other time indicators are given   
   *  @return string   
   */
  function meridianDefault($val = null){ return $this->val('keyword_default_meridian',$val,''); }//method
  
  /**
   *  see {@link time()}
   */
  function timeStart(){
    $args = func_get_args();
    return $this->time('keyword_start_time',$args); 
  }//method
  
  function hasTimeStart(){ return $this->has('keyword_start_time'); }//method
  
  /**
   *  see {@link time()}
   */
  function timeStop(){
    $args = func_get_args();
    return $this->time('keyword_stop_time',$args); 
  }//method
  
  function hasTimeStop(){ return $this->has('keyword_stop_time'); }//method
  
  /**
   *  get/set time values
   *  
   *  @param  string  $key  the key the values will be stored at
   *  @parem  array $args the arguments, an array of the form: 
   *                      array('hours' => $hour,'minutes' => $minute,'meridian' => $meridian,'increment' => $increment)
   *  @return array an array of the same layout as $args            
   */
  private function time($key,$args){
  
    $ret_map = array();
  
    if(empty($args)){
    
      $ret_map = $this->val($key,null,array());
    
    }else{
    
      // set args...
      // ...for hours...
      $ret_map['hour'] = isset($args[0]) ? $args[0] : 0;
      // ...for minutes...
      $ret_map['minute'] = isset($args[1]) ? $args[1] : 0;
      // ...for meridian...
      $ret_map['meridian'] = isset($args[2]) ? $args[2] : '';
      // ...for increment...
      $ret_map['increment'] = isset($args[3]) ? $args[3] : 0;
      
      $this->val($key,$ret_map,array());
    
    }//if/else
  
    return $ret_map;
  
  }//method
  
  /**
   *  get the set type second
   *  
   *  normally the type second is tied to a specific key, but this will return the value
   *  without bothering with the key, good for actually setting the seconds into the timestamp
   *  
   *  @return integer the set type second               
   */
  function getTypeSecond(){
    $ret_int = 0;
    if($map = $this->val('keyword_type_second',null,array())){
      $key = key($map);
      $ret_int = $map[$key];
    }//if
    return $ret_int;
  }//method
  
  /**
   *  get/set the time value, if you want something other than {@link name()}
   *  
   *  @param  string  $val  the time value to override name()   
   *  @return string  either the set timeValue or name()               
   */
  function timeValue($val = null){ return $this->val('keyword_time_value',$val,$this->name()); }//method
  
  /**
   *  a second type is meant to be hidden away in the final second field of a time (eg, the SS of HH:MM:SS)
   *  so it can only be between 0-59 to ensure it doesn't change the final time. This allows us to stuff various
   *  meta data into the second field without negatively affecting the actual date (since seconds are usually ignored)
   *  
   *  only one type second can be set, the public facing {@link getTypeSecond()} will return the value of the type second
   *  without the key it is tied to   
   *  
   *  @param  string  $key  the key where the type will be stored   
   *  @param  integer $val  between 0-59
   *  @return integer the currently set type for the given key
   */
  private function typeSecond($key,$val){
  
    $ret_int = 0;
    if($val === null){
    
      if($map = $this->val('keyword_type_second',null,array())){
        if(isset($map[$key])){ $ret_int = $map[$key]; }//if
      }//if
    
    }else{
    
      // canary...
      if(($val > 59) || ($val < 0)){ throw new Exception(sprintf('%s - $val has to be between 0-59, %d given',__METHOD__,$val)); }//if
      
      $map = array($key => $val);
      
      $this->val('keyword_type_second',$map,0);
      $ret_int = $val;
    
    }//if/else
  
    return $ret_int;
  
  }//method

}//class

?>
