<?php
if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class Logger {

	public static function debug($message=""){
		self::log('debug', $message);
	}
	
	public static function error($message){
		self::log('error', $message);
	}
	
	public static function log($level, $message=""){
		$level = strtoupper($level);
		if($level === "DEBUG" || $level === "ERROR"){
			$file		= WSWEBPAY_ROOT . "logs/webpay-logs-" . date('Y-m-d') . ".log";
			file_put_contents($file, "\n" . $level . " [".date('Y-m-d H:i:s')."]: " .$message, FILE_APPEND);			
		}
	}

	public static function dumpObject($object, $t = 0) {
		$output = "";
		if($object === null) {
			$output = "(null)";
		} else if(is_bool($object) === true) {
			$output = "boolean(".($object?"true":"false").")";
		} else if(is_array($object) || is_object($object)) {
			if(empty($object)) {
				$output = is_array($object)?"array()":"object()";
			} else {
				$i = 0;
				$tbs = $t;
				$output = self::tabular($tbs). (is_array($object)?"array(":"object(");				
				foreach($object as $k => $v) {
					if(is_object($v) || is_array($v)){
						$tbs++;
						$output .= "\n" .self::tabular($tbs)."$k\t: \n" . self::dumpObject($v, $tbs);
					} else {
						$output .= "\n" .self::tabular($tbs)."$k\t: $v";
					}
					$output .= "";
					$i=$i+1;
				}
				$output .= "\n)";
			}
		} else if(is_int($object) || is_integer($object) || is_long($object)) {
			$output = "(number) $object";
		} else if(is_string($object)){
			$output = "(string) $object";
		}
		return $output;
	}

	private static function tabular($index = 0) {
		$tabs = "";
		for($i = 0; $i < $index; $i++){
			$tabs .="\t";
		}
		return $tabs;
	}

}