<?php

/**
 * Orokos.com dice roller library
 *
 * Changes:
 *  1.0     Release
 *  1.1     Limits capability and seperation of roller and render
 *  1.2     Updated EotE dice, added result types
 *  1.3     Added Double Cross dice (XxxY)
 *  1.4     Added King of Tokyo dice (XxK)
 *  1.5     Added Warhammer Fantasy Roleplay dice (XwY)
 *
 * @author Daniel Major <dmajor@gmail.com>
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2013 Daniel Major
 * @version 1.5
 * @license proprietary
 * @package orokos
 */

class OrokosRoller
{
	/**
	 * Roll expression limits
	 * @var array
	 */
	protected static $limits;

	/**
	 * Modify parser limits
	 *
	 * Takes a single keyed array of the format:
	 * 
	 *    rolls          Number of rolls per die
	 *    sides          Number of sides per die
	 *    expr           Number of expressions per invocation
	 *    parts          Number of parts per expression
	 *    tokens         Number of tokens per part
	 *    times          Number of variable rolls
	 *    combinatoric   Maximum range for combinatorics/permutations
	 *
	 * @param array $limits
	 */
	public static function limits($limits = null)
	{
		$defaults = array(
			'rolls' => 100,
			'sides' => 10000,
			'expr' => 20,
			'parts' => 4,
			'tokens' => 100,
			'times' => 100,
			'combinatoric' => 1000,
		);

		if (!is_array(self::$limits))
			self::$limits = $defaults;

		// Set if data provided
		if (!is_null($limits))
			self::$limits = array_merge(self::$limits, $limits);

		// Always return
		return self::$limits;
	}
	
	/**
	 * Get the value for a limit
	 *
	 * @param string $limit
	 * @return boolean
	 */
	public static function limit($limit, $default = null)
	{
		if (array_key_exists($limit, self::limits()))
			return self::$limits[$limit];
		return $default;
	}

	public static function error($msg)
	{
		return array('error' => $msg);
	}

	public static function is_error($result)
	{
		return isset($result['error']);
	}

	public static function render($result)
	{
		if (self::is_error($result)) { return 'error'; }
		$str = '';
		foreach ($result as $roll)
		{
			$str .= '<u>' . $roll['roll'] . '</u>: ';
			$parts = array();
			for ($part = 0; $part < count($roll['result']); $part++)
			{
				$results = array();
				if ($part == count($roll['result']) - 1)
				{
					// final part
					foreach ($roll['result'][$part]['result'] as $result)
					{
						$results[] = "<b>{$result['result']}</b>{$result['details']}";
					}
				}
				else
				{
					// # times part
					$results[0] = 0;
					foreach ($roll['result'][$part]['result'] as $result)
					{
						$results[0] += $result['result'];
						if (!empty($result['details']))
						{
							$results[] = trim($result['details']);
						}
					}
				}
				$parts[] = join(' ', $results);
			}
			$str .= join(' # ', $parts) . "\n";
		}
		return $str;
	}

	public static function symbols($result)
	{
		if (self::is_error($result)) { return 'error'; }
		$str = '';
		foreach ($result as $roll)
		{
			foreach ($roll['result'] as $part)
			{
				foreach ($part['result'] as $result)
				{
					if (isset($result['symbols']))
					{
						$symbols = '';
						foreach ($result['symbols'] as $symbol)
						{
							$symbols .= '<img class="symbol" src="images/' . $symbol . '">';
						}
						if (!empty($symbols)) { $str .= '<br>' . $symbols; }
					}
				}
			}
		}
		return $str;
	}

	private static function factorial($n)
	{
		$f = '1';
		while ($n > 1)
		{
			$f = bcmul($f, "$n");
			$n--;
		}
		return $f;
	}

	private static function opprec($op)
	{
		$prec = 0;
		switch ($op)
		{
		case '+':
		case '-':
			$prec = 1;
			break;
		case '*':
		case '/':
			$prec = 2;
			break;
		}
		return $prec;
	}

	public static function roller($dice)
	{
		$results = array();
		$rolls = explode(';', preg_replace('@ *([;()*/+-]) *@', '$1', trim($dice)));
		if (count($rolls) > self::limit('expr')) { return self::error('limit'); }
		foreach ($rolls as $next_roll)
		{
			$roll_results = array();
			$times = 1;
			$parts = explode('#', $next_roll);
			if (count($parts) > self::limit('parts')) { return self::error('limit'); }
			for ($part = 0; $part < count($parts); $part++)
			{
				if ($times > self::limit('times')) { return self::error('limit'); }
				$part_results = array();
				$newtimes = 0;
				for ($time = 0; $time < $times; $time++)
				{
					$tokens = preg_split('@([()*/+-])@', $parts[$part], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
					if ($tokens)
					{
						if (count($tokens) > self::limit('tokens')) { return self::error('limit'); }
							// (1d6+2 is 3 tokens, 4#1d20+1d8+4 is 1 token and 5 tokens, 1d20+(1d8+4) is 7 tokens)
						// convert to postfix
						$stack = array();
						$terms = array();
						foreach ($tokens as $token)
						{
							if (self::opprec($token))
							{
								while ((!empty($stack)) && (end($stack) != '(') && (self::opprec(end($stack)) >= self::opprec($token)))
								{
									array_push($terms, array_pop($stack));
								}
								array_push($stack, $token);
							}
							else if ($token == '(')
							{
								array_push($stack, $token);
							}
							else if ($token == ')')
							{
								while ((!empty($stack)) && (end($stack) != '('))
								{
									array_push($terms, array_pop($stack));
								}
								if (array_pop($stack) != '(')
								{
									return self::error('syntax');
								}
							}
							else
							{
								array_push($terms, $token);
							}
						}
						while (!empty($stack))
						{
							array_push($terms, array_pop($stack));
						}
						// evaluate
						$details = '';
						$symbols = array();
						foreach ($terms as $term)
						{
							if (self::opprec($term))
							{
								$val2 = array_pop($stack);
								$val1 = array_pop($stack);
								if (is_null($val1) || is_null($val2))
								{
									return self::error('syntax');
								}
								if ($val1['type'] == 'numeric' && $val2['type'] == 'numeric')
								{
									switch ($term)
									{
									case '*': $val = array('type' => 'numeric', 'value' => $val1['value'] * $val2['value']); break;
									case '/': $val = array('type' => 'numeric', 'value' => floor($val1['value'] / $val2['value'])); break;
									case '+': $val = array('type' => 'numeric', 'value' => $val1['value'] + $val2['value']); break;
									case '-': $val = array('type' => 'numeric', 'value' => $val1['value'] - $val2['value']); break;
									default: return self::error('syntax');
									}
									array_push($stack, $val);
								}
								else if ($val1['type'] == 'eote_check' && $val2['type'] == 'eote_check')
								{
									switch ($term)
									{
									case '+': $val = array('type' => 'eote_check', 'value' => array(
											$val1['value'][0] + $val2['value'][0],
											$val1['value'][1] + $val2['value'][1],
											$val1['value'][2] + $val2['value'][2],
											$val1['value'][3] + $val2['value'][3],
											$val1['value'][4] + $val2['value'][4],
											$val1['value'][5] + $val2['value'][5],
										));
										break;
									default: return self::error('syntax');
									}
									array_push($stack, $val);
								}
								else if ($val1['type'] == 'wfrp_check' && $val2['type'] == 'wfrp_check')
								{
									switch ($term)
									{
									case '+': $val = array('type' => 'wfrp_check', 'value' => array(
											$val1['value'][0] + $val2['value'][0],
											$val1['value'][1] + $val2['value'][1],
											$val1['value'][2] + $val2['value'][2],
											$val1['value'][3] + $val2['value'][3],
											$val1['value'][4] + $val2['value'][4],
											$val1['value'][5] + $val2['value'][5],
										));
										break;
									default: return self::error('syntax');
									}
									array_push($stack, $val);
								}
								else
								{
									return self::error('syntax');
								}
							}
							else
							{
								if (preg_match('/^\d+$/', $term))
								{
									array_push($stack, array('type' => 'numeric', 'value' => $term));
								}
								else if (preg_match('/^(\d+)(c|p)(\d+)$/', $term, $matches))
								{
									$n = intval($matches[1]);
									$k = intval($matches[3]);
									if ($n > self::limit('combinatoric')) { return self::error('limit'); }
									if ($k > $n) { return self::error('syntax'); }
									$detail = $n > 0 ? range(1, $n) : array();
									shuffle($detail);
									$detail = array_slice($detail, 0, $k);
									if ($matches[2] == 'c')
									{
										sort($detail);
										$val = 0.0 + bcdiv(bcdiv(self::factorial($n), self::factorial($n - $k)), self::factorial($k));
									}
									else
									{
										$val = 0.0 + bcdiv(self::factorial($n), self::factorial($n - $k));
									}
									array_push($stack, array('type' => 'numeric', 'value' => $val));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)e(A|B|C|D|F|P|S)$/', $term, $matches))
								{
									$edice = array(
										'A' => array('-', 'S', 'S', 'S/S', 'A', 'A', 'S/A', 'A/A'),
										'B' => array('-', '-', 'A/A', 'A', 'S/A', 'S'),
										'C' => array('-', 'F', 'F', 'F/F', 'F/F', 'Th', 'Th', 'F/Th', 'F/Th', 'Th/Th', 'Th/Th', 'D'),
										'D' => array('-', 'F', 'F/F', 'Th', 'Th', 'Th', 'Th/Th', 'F/Th'),
										'F' => array('DS', 'DS', 'DS', 'DS', 'DS', 'DS', 'DS/DS', 'LS', 'LS', 'LS/LS', 'LS/LS', 'LS/LS'),
										'P' => array('-', 'S', 'S', 'S/S', 'S/S', 'A', 'S/A', 'S/A', 'S/A', 'A/A', 'A/A', 'Tr'),
										'S' => array('-', '-', 'F', 'F', 'Th', 'Th'),
									);
									$min = 1;
									$max = count($edice[$matches[2]]);
									$detail = array();
									$result = array(0, 0, 0, 0, 0, 0);
									$numdice = intval($matches[1]);
									if ($numdice > self::limit('rolls')) { return self::error('limit'); }
									for ($i = 0; $i < $numdice; $i++)
									{
										$roll = mt_rand($min, $max);
										$ediceroll = $edice[$matches[2]][$roll - 1];
										foreach (explode('/', $ediceroll) as $r)
										{
											switch ($r)
											{
											case 'Tr':
												$result[2]++;
											case 'S':
												$result[0]++;
												break;
											case 'D':
												$result[3]++;
											case 'F':
												$result[0]--;
												break;
											case 'A':
												$result[1]++;
												break;
											case 'Th':
												$result[1]--;
												break;
											case 'LS':
												$result[4]++;
												break;
											case 'DS':
												$result[5]++;
												break;
											}
										}
										$detail[] = $ediceroll;
										$symbols[] = 'eote/' . strtolower($matches[2] . '-' . str_replace('/', '-', $ediceroll)) . '.png';
									}
									array_push($stack, array('type' => 'eote_check', 'value' => $result));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)w(B|G|K|P|R|W|Y)$/', $term, $matches))
								{
									$edice = array(
										'B' => array('-', '-', 'S', 'S', 'S', 'S', 'Bo', 'Bo'),
										'G' => array('-', 'S', 'S', 'S', 'S', 'Bo', 'Bo', 'S/Bo', 'S/D', 'S/D'),
										'K' => array('-', '-', '-', 'C', 'C', 'Ba'),
										'P' => array('-', 'C', 'C', 'C/C', 'C/C', 'Ba', 'Ba/Ba', 'CS'),
										'R' => array('-', '-', 'S/S', 'S/S', 'Bo/Bo', 'S/Bo', 'Ba', 'Ba', 'S/E', 'S/E'),
										'W' => array('-', '-', '-', 'S', 'S', 'Bo'),
										'Y' => array('-', 'S', 'RS', 'Bo', 'Bo', 'SC'),
									);
									$min = 1;
									$max = count($edice[$matches[2]]);
									$detail = array();
									$result = array(0, 0, 0, 0, 0, 0);
									$numdice = intval($matches[1]);
									if ($numdice > self::limit('rolls')) { return self::error('limit'); }
									for ($i = 0; $i < $numdice; $i++)
									{
										$roll = mt_rand($min, $max);
										$ediceroll = $edice[$matches[2]][$roll - 1];
										foreach (explode('/', $ediceroll) as $r)
										{
											switch ($r)
											{
											case 'RS':
												if ($numdice < self::limit('rolls')) { $numdice++; }
											case 'S':
												$result[0]++;
												break;
											case 'C':
												$result[0]--;
												break;
											case 'Bo':
												$result[1]++;
												break;
											case 'Ba':
												$result[1]--;
												break;
											case 'D':
												$result[2]++;
												break;
											case 'E':
												$result[3]++;
												break;
											case 'SC':
												$result[4]++;
												break;
											case 'CS':
												$result[5]++;
												break;
											}
										}
										$detail[] = $ediceroll;
										$symbols[] = 'wfrp/' . strtolower($matches[2] . '-' . str_replace('/', '-', $ediceroll)) . '.png';
									}
									array_push($stack, array('type' => 'wfrp_check', 'value' => $result));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)xx(\d+)$/', $term, $matches))
								{
									$min = 1;
									$max = 10;
									$result = 0;
									$detail = array();
									$numdice = intval($matches[1]);
									if ($numdice > self::limit('rolls')) { return self::error('limit'); }
									$extra = intval($matches[2]);
									$rerolls = 99;
									while ($numdice > 0 && $rerolls >= 0)
									{
										$rolls = array();
										$keep = array();
										for ($i = 0; $i < $numdice; $i++)
										{
											$roll = mt_rand($min, $max);
											$rolls[] = $roll;
											if ($roll >= $extra) { $keep[] = $roll; }
										}
										$detail[] = '[' . join(', ', $rolls) . ']';
										$numdice = count($keep);
										if ($numdice > 0)
										{
											$result += 10;
											$rerolls--;
										}
										else
										{
											$result += max($rolls);
										}
									}
									array_push($stack, array('type' => 'numeric', 'value' => $result));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)x(M)$/', $term, $matches))
								{
									$xdice = array(
										'M' => array(
											'Black|mk/mana-black.png',
											'White|mk/mana-white.png',
											'Red|mk/mana-red.png',
											'Green|mk/mana-green.png',
											'Blue|mk/mana-blue.png',
											'Gold|mk/mana-gold.png',
										),
									);
									$min = 1;
									$max = count($xdice[$matches[2]]);
									$detail = array();
									$result = array();
									$numdice = intval($matches[1]);
									if ($numdice > self::limit('rolls'))  { return self::error('limit'); }
									for ($i = 0; $i < $numdice; $i++)
									{
										$roll = mt_rand($min, $max);
										$bits = explode('|', $xdice[$matches[2]][$roll - 1]);
										$detail[] = $bits[0];
										if (isset($bits[1])) { $symbols[] = $bits[1]; }
										$result[] = $roll;
									}
									array_push($stack, array('type' => 'numeric', 'value' => array_sum($result)));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)x(K)([123hea]*)$/', $term, $matches))
								{
									$xdice = array(
										'K' => array(
											'1|kt/kt-1.png|kt/kt-1-l.png',
											'2|kt/kt-2.png|kt/kt-2-l.png',
											'3|kt/kt-3.png|kt/kt-3-l.png',
											'Heart|kt/kt-h.png|kt/kt-h-l.png',
											'Energy|kt/kt-e.png|kt/kt-e-l.png',
											'Attack|kt/kt-a.png|kt/kt-a-l.png',
										),
									);
									$min = 1;
									$max = count($xdice[$matches[2]]);
									$detail = array();
									$result = array();
									$numdice = intval($matches[1]);
									if ($numdice > self::limit('rolls'))  { return self::error('limit'); }
									$loaded = $matches[3];
									for ($i = 0; $i < $numdice; $i++)
									{
										$symtype = 2;
										switch ($loaded[$i])
										{
										case '1': $roll = 1; break;
										case '2': $roll = 2; break;
										case '3': $roll = 3; break;
										case 'h': $roll = 4; break;
										case 'e': $roll = 5; break;
										case 'a': $roll = 6; break;
										default: $symtype = 1; $roll = mt_rand($min, $max);
										}
										$bits = explode('|', $xdice[$matches[2]][$roll - 1]);
										$detail[] = $bits[0];
										if (isset($bits[1])) { $symbols[] = $bits[$symtype]; }
										$result[] = $roll;
									}
									array_push($stack, array('type' => 'numeric', 'value' => array_sum($result)));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)x(R)$/', $term, $matches))
								{
									$xdice = array(
										'R' => array(
											't|runes/01.png',
											'b|runes/02.png',
											'e|runes/03.png',
											'm|runes/04.png',
											'l|runes/05.png',
											'ng|runes/06.png',
											'd|runes/07.png',
											'o|runes/08.png',
											'h|runes/09.png',
											'n|runes/10.png',
											'i|runes/11.png',
											'j|runes/12.png',
											'ei|runes/13.png',
											'p|runes/14.png',
											'z|runes/15.png',
											's|runes/16.png',
											'f|runes/17.png',
											'u|runes/18.png',
											'th|runes/19.png',
											'a|runes/20.png',
											'r|runes/21.png',
											'k|runes/22.png',
											'g|runes/23.png',
											'w|runes/24.png',
										),
									);
									$min = 1;
									$max = count($xdice[$matches[2]]);
									$detail = array();
									$result = array();
									$numdice = intval($matches[1]);
									if ($numdice > $max) { return self::error('limit'); }
									$draw = range($min, $max);
									shuffle($draw);
									for ($i = 0; $i < $numdice; $i++)
									{
										$roll = $draw[$i];
										$bits = explode('|', $xdice[$matches[2]][$roll - 1]);
										$detail[] = $bits[0];
										if (isset($bits[1])) { $symbols[] = $bits[1]; }
										$result[] = $roll;
									}
									array_push($stack, array('type' => 'numeric', 'value' => array_sum($result)));
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else if (preg_match('/^(\d+)d(\d+(r\d+|m\d+)?([eo]\d+)?([thxukl]\d+)?|F|C)$/', $term, $matches))
								{
									if ($matches[2] == 'F')
									{
										$min = -1;
										$max = 1;
									}
									else if ($matches[2] == 'C')
									{
										$min = 1;
										$max = 10;
									}
									else
									{
										$min = (!empty($matches[3]) && $matches[3][0] == 'r') ? intval(substr($matches[3], 1)) + 1 : 1;
										$minraise = (!empty($matches[3]) && $matches[3][0] == 'm') ? intval(substr($matches[3], 1)) : 0;
										$max = intval($matches[2]);
										$min = min($min, $max);
										$minraise = min($minraise, $max);
									}
									$detail = array();
									$result = array();
									$numdice = intval($matches[1]);
									if ($numdice > self::limit('rolls')) { return self::error('limit'); }
									if ($max > self::limit('sides')) { return self::error('limit'); }
									for ($i = 0; $i < $numdice; $i++)
									{
										if ($matches[2] == 'F')
										{
											$roll = mt_rand($min, $max);
											$detail[] = $roll > 0 ? '+' . $roll : $roll;
											$result[] = $roll;
										}
										else if ($matches[2] == 'C')
										{
											$roll = mt_rand($min, $max);
											$detail[] = $roll;
											$result[] = $roll;
										}
										else
										{
											$extra = 0;
											$rerolls = 0;
											$target = !empty($matches[5]) ? intval(substr($matches[5], 1)) : 0;
											if (!empty($matches[5]) && $matches[5][0] == 'h')
											{
												$extra = $max;
												$rerolls = 99;
											}
											else if (!empty($matches[5]) && $matches[5][0] == 't' && $target > $max)
											{
												$extra = $max;
												$rerolls = 99;
											}
											if (!empty($matches[4]) && ($matches[4][0] == 'e' || $matches[4][0] == 'o'))
											{
												$extra = intval(substr($matches[4], 1));
												$rerolls = $matches[4][0] == 'e' ? 1 : 99;
											}
											$roll = mt_rand($min, $max);
											if ($roll < $minraise) $roll = $minraise;
											$rolls = array($roll);
											while ($rerolls > 0 && $roll >= $extra)
											{
												$roll = mt_rand($min, $max);
												if ($roll < $minraise) $roll = $minraise;
												$rolls[] = $roll;
												$rerolls--;
											}
											switch (!empty($matches[5]) ? $matches[5][0] : '')
											{
											case 't':
												$sum = array_sum($rolls);
												$detail[] = $sum;
												if ($sum >= $target) { $result[] = 1; }
												break;
											case 'h':
												$detail[] = count($rolls) > 1 ? '[' . join(', ', $rolls) . ']' : $rolls[0];
												foreach ($rolls as $roll)
												{
													if ($roll >= $target) { $result[] = 1; }
												}
												break;
											case 'x':
												$detail[] = count($rolls) > 1 ? '[' . join(', ', $rolls) . ']' : $rolls[0];
												foreach ($rolls as $roll)
												{
													if ($roll >= $target) { $result[] = 1; }
													if ($roll == $max) { $result[] = 1; }
												}
												break;
											case 'u':
												$sum = array_sum($rolls);
												$detail[] = $sum;
												if ($sum <= $target) { $result[] = 1; }
												break;
											default:
												$sum = array_sum($rolls);
												$detail[] = $sum;
												$result[] = $sum;
											}
										}
									}
									if (!empty($matches[5]) && ($matches[5][0] == 'k' || $matches[5][0] == 'l'))
									{
										sort($result);
										if ($matches[5][0] == 'k') { $result = array_reverse($result); }
										$keep = array_slice($result, 0, $target);
										$detail = array_slice($result, $target);
										if (count($keep) > 0)
										{
											array_unshift($detail, '[' . join(', ', $keep) . ']');
										}
										$result = $keep;
									}
									if ($matches[2] == 'C')
									{
										$sum = max($result);
										$counts = array_count_values($result);
										$straight = 0;
										for ($r = 1; $r <= 10; $r++)
										{
											if ($counts[$r] * $r > $sum)
											{
												$sum = $counts[$r] * $r;
											}
											if ($counts[$r] == 0)
											{
												$straight = 0;
											}
											else
											{
												$straight++;
												if ($straight >= 3)
												{
													$straightsum = $straight * ($r + $r - $straight + 1) / 2;
													if ($straightsum > $sum)
													{
														$sum = $straightsum;
													}
												}
											}
										}
										array_push($stack, array('type' => 'numeric', 'value' => $sum));
									}
									else
									{
										array_push($stack, array('type' => 'numeric', 'value' => array_sum($result)));
									}
									$details .= " [$term=" . join(', ', $detail) . "]";
								}
								else
								{
									return self::error('syntax');
								}
							}
						}
						if (count($stack) != 1)
						{
							return self::error('syntax');
						}
						$result = array_pop($stack);
						if ($result['type'] == 'numeric')
						{
							$part_results[] = array(
								'result' => $result['value'],
								'details' => $details,
								'symbols' => $symbols,
							);
							$newtimes += $result['value'];
						}
						else if ($result['type'] == 'eote_check')
						{
							$eote_success = $result['value'][0];
							$eote_advantage = $result['value'][1];
							$eote_triumph = $result['value'][2];
							$eote_despair = $result['value'][3];
							$eote_lightside = $result['value'][4];
							$eote_darkside = $result['value'][5];
							$eote_result = array();
							if ($eote_success < -1) { $eote_result[] = (-$eote_success) . ' failures'; }
							else if ($eote_success == -1) { $eote_result[] = '1 failure'; }
							else if ($eote_success == 0) { $eote_result[] = '0 successes'; }
							else if ($eote_success == 1) { $eote_result[] = '1 success'; }
							else { $eote_result[] = $eote_success . ' successes'; }
							if ($eote_success == 0 && $eote_lightside + $eote_darkside > 0) { $eote_result = array(); }
							if ($eote_advantage < 0) { $eote_result[] = (-$eote_advantage) . ' threat'; }
							else if ($eote_advantage > 0) { $eote_result[] = $eote_advantage . ' advantage'; }
							if ($eote_triumph > 0) { $eote_result[] = $eote_triumph . ' Triumph'; }
							if ($eote_despair > 0) { $eote_result[] = $eote_despair . ' Despair'; }
							if ($eote_lightside > 0) { $eote_result[] = $eote_lightside . ' Light Side'; }
							if ($eote_darkside > 0) { $eote_result[] = $eote_darkside . ' Dark Side'; }
							$part_results[] = array(
								'result' => join(', ', $eote_result),
								'details' => $details,
								'symbols' => $symbols,
							);
							if ($part < count($parts) - 1)
							{
								return self::error('syntax');
							}
						}
						else if ($result['type'] == 'wfrp_check')
						{
							$wfrp_success = $result['value'][0];
							$wfrp_boon = $result['value'][1];
							$wfrp_delay = $result['value'][2];
							$wfrp_exertion = $result['value'][3];
							$wfrp_comet = $result['value'][4];
							$wfrp_chaos = $result['value'][5];
							$wfrp_result = array();
							if ($wfrp_success < -1) { $wfrp_result[] = (-$wfrp_success) . ' challenges'; }
							else if ($wfrp_success == -1) { $wfrp_result[] = '1 challenge'; }
							else if ($wfrp_success == 0) { $wfrp_result[] = '0 successes'; }
							else if ($wfrp_success == 1) { $wfrp_result[] = '1 success'; }
							else { $wfrp_result[] = $wfrp_success . ' successes'; }
							if ($wfrp_boon < 0) { $wfrp_result[] = (-$wfrp_boon) . ' bane'; }
							else if ($wfrp_boon > 0) { $wfrp_result[] = $wfrp_boon . ' boon'; }
							if ($wfrp_delay > 0) { $wfrp_result[] = $wfrp_delay . ' delay'; }
							if ($wfrp_exertion > 0) { $wfrp_result[] = $wfrp_exertion . ' exertion'; }
							if ($wfrp_comet > 0) { $wfrp_result[] = $wfrp_comet . ' Sigmar\'s Comet'; }
							if ($wfrp_chaos > 0) { $wfrp_result[] = $wfrp_chaos . ' Chaos Star'; }
							$part_results[] = array(
								'result' => join(', ', $wfrp_result),
								'details' => $details,
								'symbols' => $symbols,
							);
							if ($part < count($parts) - 1)
							{
								return self::error('syntax');
							}
						}
					}
					else
					{
						return self::error('syntax');
					}
				}
				$roll_results[] = array(
					'part' => $parts[$part],
					'result' => $part_results,
				);
				$times = $newtimes;
			}
			$results[] = array(
				'roll' => $next_roll,
				'result' => $roll_results,
			);
		}
		return $results;
	}
}
