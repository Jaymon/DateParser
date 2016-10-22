<?php

class PlanGeoTest extends PHPUnitFixture
{
  public function setUp()
  {
    // we want everything to be UTC...
    date_default_timezone_set('UTC');
  
  }//method

  /**
   *  test a date like M/D/Y - M/D/Y
   *
   *  @since  11-8-10
   */
  public function test_M_D_Y_Span()
  {
    $p = new parse_date();
    
    $tz_offset = 0;
    
    // wanted date support for this format...
    $input_start = '10/8/2010';
    $input_stop = '10/10/2010';
    $input = sprintf('%s - %s',$input_start,$input_stop);
    
    $expected_start = strtotime($input_start);
    $expected_stop = strtotime('10/11/2010');
    
    $m_list = $p->findInField($input,$tz_offset);
    $this->assertSame(1,count($m_list));
    
    ///out::e(time::full($expected_start),time::full($m_list[0]->start()));
    ///out::e(time::full($expected_stop),time::full($m_list[0]->stop()));
    
    $this->assertSame($expected_start,$m_list[0]->start());
    $this->assertSame($expected_stop,$m_list[0]->stop());
    
  }//method

  public function xtestMartinDateRequest()
  {
    $p = new parse_date();
    
    $tz_offset = 0;
    
    // wanted date support for this format...
    $input_start = '10/8/2010 8:00';
    $input_stop = '10/10/2010 10:00';
    $input = sprintf('%s - %s',$input_start,$input_stop);
    
    $expected_start = strtotime($input_start);
    $expected_stop = strtotime($input_stop);
    
    $m_list = $p->findInField($input,$tz_offset);
    out::e($m_list[0]);
    $this->assertSame(1,count($m_list));
    
    $this->assertSame($expected_start,$m_list[0]->start());
    $this->assertSame($expected_stop,$m_list[0]->stop());
    
  }//method

  public function testBugfixTantek()
  {
    $p = new parse_date();
    
    $tz_offset = 0;
    
    // originally reported the bug with dashes between the dates, the problem is we don't
    // support that do to a limitation of time and other date ranges (the dash is used for ranges)
    // however, the / syntax doesn't work either, so let's test that...
    $input = "2010-10-27 7pm";
    
    $input = "2010/10/27 7pm";
    $expected_timestamp = strtotime($input);
    
    $m_list = $p->findInField($input,$tz_offset);
    $this->assertSame(1,count($m_list));
    
    $this->assertSame($expected_timestamp,$m_list[0]->start());
    $this->assertSame($expected_timestamp,$m_list[0]->stop());
    
  }//method
  
}//class

