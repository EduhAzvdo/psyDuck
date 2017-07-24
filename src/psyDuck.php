<?php 

namespace psyDuck;

/**
*       PHP psyDuck 
*  Just a simple file-based database for php projects
*
*  The very first thing to do when instantiating a new object, is to set the storage folder, 
* wich can be done by passing as string argument in the object constructor or by the setContainer method.
*  And it's also important to check the write permissions of the defined storage folder
*
* @author Eduardo Azevedo <eduh.azvdo@gmail.com>
* @version 0.01
*/

class psyDuck
{

	// the folder path to store the json files
	private $container_path;
	private $file_pointer; // current json file pointer
	private $file_name; // currente json file name
	private $supply_file_pointer; // pointer for temporary file, used in transations
	private $supply_file_name; // temporay file name
	
	/**
	 * On contruct verify if was passed a conteiner, which can be defined later
	 * @param string $container (optional) a folder path which can be defined later
	 */
	function __construct( $container = null )
	{
		if ($container != null)
			$this->setContainer($container);
	}

	/**
	 * Set the folder to store the data files
	 *  try to create if doens't exist
	 * @param string $path 
	 */
	public function setContainer ( $path )
	{
		if($path)
			$path .= DIRECTORY_SEPARATOR;
		else
			return false;

		if ( !is_dir($path) ){ // if doesn't exist
			if ( !@mkdir( $path, 0777, true ) ) // try to create
				throw new \Exception("Fail when trying to create storage folder on path '{$path}'.\n check write permissions.");
		}

		if ( !is_writable($path) ) // if is not writable
			throw new \Exception("the especified path ({$path}) is not writable.");
		
		$this->container_path = $path;
		return true;
	}

	/**
	 * Define the name of the file container to store data (works like a table)
	 * @param  string $file define the storage file, must be passed without the (dot).JSONL extension
	 * @return object  (return a instance of the obj itself, helps with chain methods)
	 */
	public function in ( $file )
	{
		$this->close();
		$this->file_name = $file;
		$file = $this->container_path . $file . '.jsonl';
		if($this->file_pointer = fopen( $file, 'a+') ) {
			return $this;
		} else {
			throw new \Exception("Failed to open the file to storage, check the write permissions of the {$this->container_path} directory");
		}
	}

	/**
	 * insert a new data line in the storage file
	 * @param  array  $data 
	 * @param  boolean $mult if true, insert as a multi line array
	 * @return void
	 */
	public function insert ( $data, $mult=false )
	{
		if (!is_array($data)) 
			throw new \Exception('The argument must be array');
		if (!$this->file_pointer) 
			throw new \Exception('File pointer not defined');

		if ( $mult === true ) {
			for ($i=0; $i < count($data); $i++) { 
				$this->insert( $data[$i] );
			}
		} else {
			$_content = json_encode($data) . PHP_EOL;
			if (!fwrite( $this->file_pointer, $_content))
				throw new \Exception('Fails to write file');
		}
	}

	/**
	 * runs the list of values and if the value being pointed return some value or true, stop the loop.
	 *           if true, it returns the entire object, if nothing found return false.
	 * @param  function $callbk
	 * @return array|false
	 */
	public function get ( $callbk )
	{
		if (is_callable($callbk)) {
			foreach ($this->fetch() as $value):
				$func_result = $callbk( $value );
				if( true === $func_result ):
					return $value;
				elseif( ! $func_result ):
					continue;
				else:
					return $func_result;
				endif;
			endforeach;
			return false;
		} else {
			throw new \Exception("the passed argument must be callable");
		}
	}

	/**
	 * Sugar for the function "each", if no closure filter function defined, return all data
	 * @param  function $pattern filter the data
	 * @return Generator
	 * @todo maybe create a parser to interpret conditions in a string, or some thing like that....
	 */
	public function find ( $pattern=null )
	{
		if (is_callable($pattern)) {
			return $this->each( $pattern, true );
		} else {
			return $this->fetch();
		}
	}

	/**
	 * Return a array with the result of function each or fetch if no closure function was passed
	 *     must be used with careful
	 * @param  function $pattern filter the data
	 * @return array
	 */
	public function node ( $pattern=null )
	{
		if (is_callable($pattern))
			$generator = $this->each( $pattern, true );
		else
			$generator = $this->fetch();

		$result = array();
		
		foreach ( $generator as $line):
			$result[] = $line;
		endforeach;

		return $result;
	}

	/**
	 * Aplly a closure function in each parsed element returned by "fetch"
	 *    if $filter equal false, the arg function must return, strictly a boolean
	 * @param  function  $func   
	 * @param  boolean $filter if defined as true, will expect a boolean result to retrieve data
	 * @return Generator
	 */
	public function each ( $func, $filter=false )
	{
		if (is_callable($func)) {
			foreach ($this->fetch() as $value):
				$func_result = $func( $value );
								//@todo need find a bettter way to avoid empty results
				if ($filter):
					if(false == $func_result)	continue;
					if(true === $func_result)	yield $value;
					else						yield $func_result;
				else:
					if( true === $func_result )
						yield $value;
					elseif ( false != $func_result )
						throw new \Exception("The return of the closure function must be boolean...");
				endif;
			endforeach;
		}
	}

	/**
	 * Return all data from the store file
	 * @return Generator
	 */
	public function fetch ()
	{
		if (!$this->file_pointer) 
			throw new \Exception('File pointer not defined');
		rewind( $this->file_pointer );
		while ( false !== ($line = fgets($this->file_pointer)) ) {
			yield json_decode( $line, true );
		}
	}

	/**
	 * Delete a row line
	 *  the closure function should return "true" when receives the value of array/line index to delete
	 * @param  function $pattern should return true to delete de current index
	 * @return void
	 */
	public function delete ( $pattern=null )
	{
		$this->start_supply();
		if (is_callable($pattern)) {
			foreach ($this->fetch() as $data):
				if ( $pattern( $data ) !== true):
					$this->write_supply( $data );
				endif;
			endforeach;
		} else {
			throw new \Exception("do you feel lucky? ... sure? why are you calling the delete function without a closure function to filter?");
		}
		$this->set_supply();
	}

	/**
	 * update as modified inside the passed closure function
	 * the closure function's argument must be passed as reference, in order to alter the data
	 * ** the modifications can be directly returned as well
	 * @param  function $pattern the argument should be set as reference (with & prefix)
	 * @return void
	 */
	public function update ( $pattern=null )
	{
		$this->start_supply();
		if (is_callable($pattern)) {
			foreach ($this->fetch() as $data):
					if ( $func_result = $pattern( $data ) )
						$this->write_supply( $func_result );
					else
						$this->write_supply( $data );
			endforeach;
		} else {
			throw new \Exception("the update function needs a callback argument");
		}
		$this->set_supply();
	}

	/**
	 * Do a sort in the file container data, by the pattern function
	 * 		 the  cycle number of verifications by each request is defined by $loop var
	 * ** ** ** (for while, this method does nothing. is here just to remember to implement later)
	 * 
	 * @param  function $pattern pattern sort function
	 * @param  integer $loop number of verification cycles for each request
	 * @return void  an arranged storage file
	 * @todo make it work for real
	 */
	public function arrange ( $pattern, $loop=1 )
	{
		# yet to come
	}

	/**
	 * Do a search within all storage files, applying the $pattern function as parser 
	 *	if the pattern closure function explicitly return true, the seek loop stops returning the current storage file name 
	 *		this function can be called before the ->in() method, \(^_^)/	 
	 * ** ** must order the files list to parse by recently modified files
	 *		 	
	 * ** ** ** (for while, this method does nothing. is here just to remember to implement later) '(º_^)'
	 * 
	 * @param  function $pattern 
	 * @return string|void  if the pattern function does match true, return the current storage file name in parsed
	 * @todo make it work for real
	 */
	public function seek ( $pattern )
	{
		# yet to come
	}

	/**
	 * Return the number of rows in the current storage file
	 * @return integer 
	 */
	public function count ()
	{
		$counter = 0;
		rewind( $this->file_pointer );
		while ( false !== ( $line = fgets($this->file_pointer, 10) ) )
			$counter = $counter + substr_count($line, PHP_EOL);
		return $counter;
	}

	/**
	 * Delete a entire storage file, returning excluded data
	 * ** ** should be used with careful
	 *  
	 * ** ** ** (for while, this method does nothing. is here just to remember to implement later) '(º_^)'
	 * 
	 * @param  [type] $file [description]
	 * @return array|boolean  return the data excluded or false
	 * @todo make it work for real
	 */
	public function drop ( $file )
	{
		# yet to come
	}

	/**
	 * create a temporary file to act as a receptor for altered data to replace the current table file
	 * @return boolean just crete the temp file
	 */
	private function start_supply ()
	{
		$this->supply_file_name = $this->file_name . '.' . uniqid();
		$temp_file = $this->container_path . $this->supply_file_name . '.jsonl';
		if($this->supply_file_pointer = fopen( $temp_file, 'w') )
			return true;
		else
			throw new \Exception("Failed in create temporary file, check the write permissions of the <i>{$this->container_path}</i> directory");
	}

	/**
	 * Write a new line in the temporary supli file
	 *  the argument must be a array who will be converted in json
	 * @param  array|string $data
	 * @param  boolean $data if true write data without encode to json format
	 * @return boolean fwrite function result
	 */
	private function write_supply ( $data, $raw=false )
	{
		$_content = ( $raw == true ? $data : json_encode($data) . PHP_EOL );
		return fwrite( $this->supply_file_pointer, $_content );
	}

	/**
	 * Define the supply file as the default, replacing the old
	 *   exluding the temporary
	 */
	private function set_supply ()
	{
		if ( is_resource( $this->supply_file_pointer ) )
			fclose($this->supply_file_pointer);
		$this->close();
		$real_file = $this->container_path . $this->file_name . '.jsonl';
		$supply_file = $this->container_path . $this->supply_file_name . '.jsonl';
		unlink( $real_file );
		rename( $supply_file, $real_file );
		$this->in( $this->file_name );
	}

	/**
	 * Fix some erros that often occur,
	 * 		* Like two data array in the same line
	 */
	private function rawfile_fix ()
	{
		$this->start_supply();
		rewind( $this->file_pointer );
		while ( false !== ($line = fgets($this->file_pointer)) ) :
				if( strpos( $line, "\"}{\"" ) !== false )
					$line = str_replace( "\"}{\"", "\"}".PHP_EOL."{\"", $line);
				if ( $line != PHP_EOL )
					$this->write_supply( $line, true );
		endwhile;
		$this->set_supply();
	}

	/**
	 * Call some examination and fix functions
     * It is not intended to be called very often
	 */
	public function checkup ()
	{
		$this->rawfile_fix();
	}

	/**
	 * For while, just close the container file pointer
	 * @return void
	 */
	private function close ()
	{
		if (!is_resource($this->file_pointer))
			return false;
		if (!fclose($this->file_pointer))
			throw new \Exception("Failed in close the storage file pointer");
	}

	function __destruct() {
		$this->close();
	}
}