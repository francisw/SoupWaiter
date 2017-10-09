<?php
namespace Waiter;

/**
 * Created by PhpStorm.
 * User: francisw
 * Date: 12/09/2017
 * Time: 11:30
 */
class Multiton extends Base  {
    protected $id;
    protected $created;


    /**
     * Get a singleton of this class.
     *
     * @param $key string
     * @return mixed
     */
	static function findOne($key){
		static $single = [];
		$class = get_called_class();

		if (!isset($single[$class])){
            $single[$class] = [];
        }

		if( empty($single[$class][$key]) ) {
			$single[$class][$key] = static::getPersisted($key);
			if (!$single[$class][$key]) {
				$single[$class][$key] = new $class();
                $single[$class][$key]->registerMultiton($key);
			}
		}
		return $single[$class][$key];
	}
	protected function registerMultiton($key){
	    // may get overlayed by child
        $this->id = $key;
        $this->created = true;
    }
}