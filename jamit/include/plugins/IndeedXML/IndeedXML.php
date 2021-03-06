<?php
# Copyright Jamit Software, 2009

# IndeedXML.php - for importing Indeed's XML Job Search Feed

# Important:
# At the bottom if the file, this statement should exist.
# $_JB_PLUGINS['IndeedXML'] = new IndeedXML; // add a new instance of the class to the global plugins array

/*
Conventions: 

- Always name your class starting with capital letters.
- The file name of the class should be the same as the directory name and file name
- Always register your callbacks in the constructor!
- Use this plugin as a starting point for your own plugin
#############################################################################
# SECURITY NOTICE                                                           #
#############################################################################
# - IMPORTANT: Always escape SQL values before putting them in to a query   #
# use the jb_escape_sql() function on ALL values                            #
#                                                                           #
# eg.                                                                       #
#                                                                           #
# $sql = "SELECT * FROM test where id='".jb_escape_sql($some_id)."' ";      #
#                                                                           #
# - IMPORTANT: Be sure to escape data before outputting it to the page      #
# use the jb_escape_html() function, and be sure to escape                  #
# $_SERVER['PHP_SELF'] with htmlentities()                                  #
#                                                                           #
# eg.                                                                       #
#                                                                           #
# echo jb_escape_html($_REQUEST['some_value']);                             #
#                                                                           #
# echo htmlentities($_SERVER['PHP_SELF']);                                  #
#                                                                           #
# - Always sanitize input to be used in functions such as                   #
# fopen(), eval(), system()                                                 #
# eg.                                                                       #
# $file_name = preg_replace ('/[^a-z^0-9]+/i', "", $_REQUEST['file_name']); #
# $fh = fopen($file_name, r);                                               #
#                                                                           #
#############################################################################
*/


if (!function_exists('JB_mysql_fetch_row')) {

	function JB_mysql_fetch_row($result, $result_type) {
		return mysql_fetch_row($result, $result_type);
	}

	function JB_mysql_fetch_array($result, $result_type) {
		return mysql_fetch_array($result, $result_type);
	}

	function JB_mysql_num_rows($result) {
		return mysql_num_rows($result);
	}
}


class IndeedXML extends JB_Plugins {

	var $config;
	var $plugin_name;

	var $result;

	var $posts = array();

	var $back_fill = array();
	var $pre_fill = array();

	var $current_post = array();

	var $fill_in_progress;

	var $curl_filename;

	var $total_results;

	function IndeedXML() {

		require (dirname(__FILE__).'/IndeedXMLParser.php'); 

		$this->plugin_name = "IndeedXML"; // set this to the name of the plugin. Case sensitive. Must be exactly the same as the directory name and class name!

		parent::JB_Plugins(); // initalize JB_Plugins

		// Prepare the config variables
		// we simply extract them from the serialized variable like this:

		if ($this->config==null) { // older versions of jamit did not init config
			$config = unserialize(JB_PLUGIN_CONFIG);
			$this->config = $config[$this->plugin_name];
		}

		# initialize the priority
		if (empty($this->config['priority'])) {
			$this->config['priority'] =5;
		}

		if (empty($this->config['k'])) {
			$this->config['k']='php';
		}

		if (($this->config['age']<1) || ($this->config['age']>30)) {
			$this->config['age']='30'; // filter results?
		}
		if (empty($this->config['r'])) {
			$this->config['r']='25'; // filter results?
		}

		if (empty($this->config['so'])) {
			$this->config['so']='date'; // sort type
		}

		if (empty($this->config['f'])) {
			$this->config['f']='1'; // filter results?
		}
		if (empty($this->config['h'])) {
			$this->config['h']='0'; // highlight keywrods?
		}

		if (empty($this->config['l'])) {
			$this->config['l']='';
		}

		if (empty($this->config['k_tag'])) {
			$this->config['k_tag'][]='TITLE';
		} else {
			// convert to array
			$this->config['k_tag'] = explode (',', $this->config['k_tag']);
		}

		if (empty($this->config['l_tag'])) {
			$this->config['l_tag'][]='LOCATION';
		} else {
			// convert to array
			$this->config['l_tag'] = explode (',', $this->config['l_tag']);
		}

		if (empty($this->config['c'])) {
			$this->config['c'] = 'us';
		}

		if (empty($this->config['lim'])) {
			$this->config['lim'] = '10';
		}



		if ($this->config['id']=='') {
			$this->config['id']='2451470435917521';
		}


	/*	if ($this->config['lim']=='') { // limit
			$this->config['lim']=10;
		}*/

		$this->config['lim'] = JB_POSTS_PER_PAGE;

		//if ($this->config['s']=='') { // server
		$this->config['s']='api.indeed.com';
		//}

		if ($this->config['curl']=='') { // cURL
			$this->config['curl']='N';
		}

		if ($this->config['fill']=='') { // results fill mode
			$this->config['fill']='S';
		}

		
		if ($this->is_enabled()) {
			// register all the callbacks
		
			///////////////////////////////////////////

			if ($this->config['fill']=='S') {
				JBPLUG_register_callback('job_list_set_count', array($this->plugin_name, 'set_count'), $this->config['priority']);
			} else {
				JBPLUG_register_callback('job_list_set_count', array($this->plugin_name, 'set_count2'), $this->config['priority']);
			}

			JBPLUG_register_callback('index_extra_meta_tags', array($this->plugin_name, 'meta_tags'), $this->config['priority']);

		
			JBPLUG_register_callback('job_list_data_val', array($this->plugin_name, 'job_list_data_val'), $this->config['priority']);

			JBPLUG_register_callback('job_list_back_fill', array($this->plugin_name, 'list_back_fill'), $this->config['priority']);

			JBPLUG_register_callback('admin_plugin_main', array($this->plugin_name, 'keyword_page'), $this->config['priority']);

			
			
		}

	}

	// returns a pointer to an open temp file
	function curl_request($host, $get) {

		$URL = "http://".$host.$get;

		$ch = curl_init();

		if ($this->config['proxy']!='') { // use proxy?
			curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, TRUE);
			curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
			curl_setopt ($ch, CURLOPT_PROXY, $this->config['proxy']);
		}


		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt ($ch, CURLOPT_URL, $URL);
		curl_setopt ($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt ($ch, CURLOPT_POST, false);
		//curl_setopt ($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);


		$result = curl_exec ($ch);
		
		curl_close ($ch);

		

		// save the result in to a temp file, utf-8 encoded
		$r = rand (1,1000000); // random number for the file-name
		$filename = $this->get_cache_dir().md5(time().$this->config['id'].$r).'_indeed.xml';
	
		$fp = fopen($filename, 'w');
		fwrite($fp, utf8_encode($result), strlen(utf8_encode($result)));
		$fp = fclose($fp);

		$this->curl_filename=$filename;

		// open for reading
		
		return fopen($filename, 'r');


	}

	function curl_cleanup($fp) {

		// delete the temp file
		
		unlink ($this->curl_filename);

	}

	function set_category_kw($cat_id, &$keyword_q, &$location_q) {

		$keyword_q = '';
		$location_q = '';

		$cat_id = (int) $cat_id;

		$sql = "SELECT * FROM IndeedXML_keywords WHERE category_id='".jb_escape_sql($cat_id)."' ";
		$result = jb_mysql_query($sql);
		$row = JB_mysql_fetch_array($result);

		if (strlen($row['kw'])>0) { // keywords
			$keyword_q = trim($row['kw']);
		} else {
			$keyword_q = JB_getCatName($cat_id); // use the category name itself as the keyword
		}

		if (strlen($row['loc'])>0) { // location
			$location_q = trim($row['loc']);
		} else {
			$location_q = $this->config['l']; // use the default location from the config
		}

		

	}

	function is_kw_OK($keyword) { // make sure the keyword not repeated
		static $arr = array();
		$keyword = trim($keyword);
		if (!in_array($keyword, $arr)) {
			$arr[] = $keyword;
			return true;
		} else {
			return false;
		}

	}
	function is_loc_OK($keyword) { // make sure location not repeated
		static $arr = array();
		$keyword = trim($keyword);
		if (!in_array($keyword, $arr)) {
			$arr[] = $keyword;
			return true;
		} else {
			return false;
		}

	}

	

	///////////////////////////////////////////
	// Customize your data processing routine below
	//


	function do_request($start='') {

		if ($start<1) { // cannot have 0 or negative
			$start = '';
		}

		$user_agent = $_SERVER['HTTP_USER_AGENT'];
		$ip_addr = $_SERVER['REMOTE_ADDR'];

		

		############################################################################
		# Process the keywords

		// Set the default keywords.
		// These will be overwritten if user inputted keywords are available
		$keyword_q = $this->config['k']; // default keywords
		$location_q = $this->config['l']; // default location


		if (is_numeric($_REQUEST['cat'])) { // fetch the category keywords
			$this->set_category_kw($_REQUEST['cat'], $keyword_q, $location_q);
		}

		if ($_REQUEST['action']=='search') { // search results, use one field for the where, and other fileld for location
			$PForm = JB_get_DynamicFormObject(1);
			$post_tag_to_search = $PForm->get_tag_to_search();

			// iterate through each search parameter
			foreach ($post_tag_to_search as $key=>$tag) {

				// is the search parameter attached to the keyword or location?
				if (in_array($key, $this->config['k_tag']) || in_array($key, $this->config['l_tag'])) {
		
					$val = $_REQUEST[$tag['field_id']]; // get what was searched for

					if (strlen($temp_keys)>0) {
						$temp_keys_space = ' ';
					}
					if (strlen($temp_loc)>0) {
						$temp_loc_space = ' ';
					}

					// convert the code or category id in to a keyword

					switch ($tag['field_type']) {
						// multiple select fields and checkboxes
						// if passed as an array, these keywords are combined with an OR
						case 'MSELECT': 
						case 'CHECK':	
							if (is_array($val)) {
								$str = ''; $or = '';
								foreach ($val as $code) {
									$str .= $or.JB_getCodeDescription ($tag['field_id'], $code);
									$or = ' or ';
								}
								$val = '('.$str.')';
							} else {
								$val = JB_getCodeDescription ($tag['field_id'], $val);
							}	
							break;
						case 'SELECT':
						case 'RADIO':
							// Single select and radio buttons.
							$val = JB_getCodeDescription ($tag['field_id'], $val);
							break;
						case 'CATEGORY':
							// grab the category config
							// If multiple categories are selected then they
							// are combined with an OR

							$cat_keywords_temp=''; $cat_location_temp=''; $or='';$i_temp=0;
							
							if (is_array($val)) { // multiple categories were searched
								$or='';
								foreach ($val as $cat_id) {
									$i_temp++;
									$this->set_category_kw($cat_id, $kw_val, $loc_val);
									if ($this->is_kw_OK($kw_val)) {
										$cat_keywords_temp .= $or.$kw_val; // append using OR
										$or = ' OR ';
									}
									if ($this->is_loc_OK($loc_val)) {
										$cat_location_temp = $loc_val;
									}									
								}
								if ($i_temp>1) {
									$cat_keywords_temp = '('.$cat_keywords_temp.')';
								} else {
									$cat_keywords_temp = $cat_keywords_temp;
								}
								
								//echo "keywords_temp: [$cat_keywords_temp] * [$cat_id]<br>got this: $kw_val $loc_val<br>";

							} else {
								
								$this->set_category_kw($val, $kw_val, $loc_val);
							
								if ($this->is_kw_OK($kw_val)) {
									$cat_keywords_temp = $kw_val;
								}
								if ($this->is_loc_OK($loc_val)) {
									$cat_location_temp = $loc_val;
								}
							}

							// add them to the keys that we are bulding
							$temp_keys .= $temp_keys_space.$cat_keywords_temp;
							// the location keys are placed in to a separate string
							$temp_cat_loc .= $temp_loc_space.$cat_location_temp;
							$temp_key_space = ' ';
							$temp_loc_space = ' ';

							$val = '';

							break;
					}

					// add the $val to the temp keywords
					if (in_array($key, $this->config['k_tag'])) { // keyword?

						$val = trim($val);
						if ($val!='') {
							// concationate the 'what' keywords
							$temp_keys .= $temp_keys_space.$val;
						}
					}

					if (in_array($key, $this->config['l_tag'])) { // location?

						$val = trim($val);
						if (($val!='')) { 
							// concatinate the 'where' keywords
							$temp_loc .= $temp_loc_space.$val;
						}

					}

				}

			} // end iterating through each parameter

			$temp_keys = trim($temp_keys);
			$temp_loc = trim($temp_loc);

			// overwrite the default value $keyword_q with the kewords that were searched
			if ($temp_keys!='') {
				$keyword_q = $temp_keys;
			}

			// Overwrite the default value $location_q with the location that was searched
			// The 'were' kywords get priority
			// If they are bank, then use the location keywords from the category if
			// available.
			if ($temp_loc!='') {
				$location_q = $temp_loc;
			} elseif ($temp_cat_loc!='') { // the 'where' keywords were empty, so perhaps they were set by a category?
			
				$location_q = $temp_cat_loc;
			}

		}
		
		############################################################################

		$channel = '&chnl='.urlencode($this->config['ch']);
		$sort = $this->config['so'];
		if ($sort=='custom') {
			$sort = 'relevance';
		}
		
		$req = 'publisher='.$this->config['id'].'&q='.urlencode($keyword_q).'&l='.urlencode($location_q).'&sort='.$sort.'&radius='.$this->config['r'].'&st='.$this->config['st'].'&jt='.$this->config['jt'].'&start='.$start.'&limit='.$this->config['lim'].'&fromage='.$this->config['age'].'&highlight='.$this->config['h'].'&filter='.$this->config['f'].'&latlong=1&userip='.urlencode($ip_addr).'&useragent='.urlencode($user_agent).'&co='.$this->config['c'].$channel.'&v=2';
		
		

		$host = $this->config['s'];//'api.indeed.com';
		$get = '/ads/apisearch?'.$req;

		// for testing:
		//$host = '127.0.0.1';
		//$get = '/JamitJobBoard-3.5.0a/include/plugins/IndeedXML/sample.xml?'.$req;
//echo $get;
		if ($this->config['curl']=='Y') {
			$fp = $this->curl_request($host, $get);		
		} else {
			$fp = @fsockopen ($host, 80, $errno, $errstr, 10);
		}
		
		if ($fp) {
		
			if ($this->config['curl']=='Y') {
				$sent = true;
			} else {
				$send  = "GET $get HTTP/1.0\r\n"; // dont need chunked so use HTTP/1.0
				$send .= "Host: $host\r\n";
				$send .= "User-Agent: Jamit Job Board (www.jamit.com)\r\n";
				$send .= "Referer: ".JB_BASE_HTTP_PATH."\r\n";
				$send .= "Content-Type: text/xml\r\n";
				$send .= "Connection: Close\r\n\r\n"; 
				$sent = fputs ($fp, $send, strlen($send) ); // get
				
			}
			
			if ($sent) { 
			
				
				while (!feof($fp)) { // skip the header
					$res = fgets ($fp);
					if (strpos($res, "<?xml")!==false) break;
				}
				
				// parse the xml file to get the posts
				$parser = new IndeedXMLParser($fp);
				$this->posts = $parser->get_posts();
				$this->total_results = $parser->total_results;

				// custom compare function for usort()
				function my_cmp($a, $b) {
					return strcmp($b["date"], $a["date"]);
				}

				// sort the results by date
				if ($this->config['so']=='custom') {
					usort($this->posts, 'my_cmp');
				}
				
			
			} else {
				//echo 'failed to send header';
			}

			fclose($fp);
			if ($this->config['curl']=='Y') {
				$this->curl_cleanup($fp);		
			} 
		} else {
			//echo "cannot connect to $host";
		}

	}


	function set_count(&$count, $list_mode) {

		if (($list_mode!='ALL') && ($list_mode!='BY_CATEGORY')) return;
		

			if ($count < JB_POSTS_PER_PAGE) { // there are some slots that can be filled

				$this->do_request();

				$free_slots = JB_POSTS_PER_PAGE-$count;

				if ($free_slots > sizeof($this->posts)) { // there are more free slots than posts
					$count = $count + (sizeof($this->posts));
				} else {
					$count = ($count + $free_slots);

				}

			} else {
				$count = $count + ($count % JB_POSTS_PER_PAGE);
			}
	}

	function set_count2(&$count, $list_mode) {

		if (($list_mode!='ALL') && ($list_mode!='BY_CATEGORY')) return;

		$offset = (int) $_REQUEST['offset'];
		
	

		if ($count > 0) {

			$max_local_pages = ceil($count / JB_POSTS_PER_PAGE);
			$max_local_offset = ($max_local_pages * JB_POSTS_PER_PAGE) - JB_POSTS_PER_PAGE;
			$last_page_local_post_count = $count % JB_POSTS_PER_PAGE; // number of local posts on the last page (remainder)
			$start_skew = JB_POSTS_PER_PAGE - $last_page_local_post_count;
			$start = (($offset-$max_local_offset)-JB_POSTS_PER_PAGE)+$start_skew;
		} elseif ($count==0) {
			$start = $offset;
		}
		
		$this->do_request($start);

		$count = $count + $this->total_results;
		

	}


	function meta_tags() {

		global $SEARCH_PAGE;
		
		global $CATEGORY_PAGE;
	
		//  job list, from index.php
		global $JOB_LIST_PAGE;

		// home page flag, from index.php
		global $JB_HOME_PAGE;

		if ($JB_HOME_PAGE || $CATEGORY_PAGE || $JOB_LIST_PAGE || $SEARCH_PAGE) {
			

			?>

			<script type="text/javascript"
	src="http://www.indeed.com/ads/apiresults.js"></script>

			<?php
		}

	}

	// include/lists.inc.php - JB_echo_job_list_data() function

	function job_list_data_val(&$val, $template_tag) {

		if (!$this->fill_in_progress) return; // is there a fill in progress?

		
		$val = '';


		$LM = &$this->JB_get_markup_obj(); // load the ListMarkup Class

		if ($template_tag=='DATE') {
			$val = JB_get_formatted_date($this->current_post['date']);
		}

		if ($template_tag=='LOCATION') {

			

			if ($this->current_post['city']) {
				$val .= $comma.$this->current_post['city'];
				$comma = ', ';
			}
			if ($this->current_post['state']) {
				$val .= $comma.$this->current_post['state'];
				$comma = ', ';
			}
			if ($this->current_post['country']) {
			//	$val .= $comma.$this->current_post['country'];
				
			}
		}


		if ($template_tag=='TITLE') {
			$val =  '<span class="job_list_title" ><A onmousedown="'.$this->current_post['onmousedown'].'" href="'.jb_escape_html($this->current_post['url']).'">'.$this->current_post['title'].'</A></span>';
		}

		
		if ($template_tag=='POST_SUMMARY') {

			$val =  '<span class="job_list_title" ><A onmousedown="'.$this->current_post['onmousedown'].'" href="'.jb_escape_html($this->current_post['url']).'">'.$this->current_post['title'].'</A></span><br>';
			$val .= '<span class="job_list_small_print">source:</span> <span class="job_list_cat_name">'.$this->current_post['source'].'</span><br>';
			$val .= '<span class="job_list_small_print">'.$this->current_post['snippet'].'</span>';
			"Post summary";
				
		}
		

	}

	function &JB_get_markup_obj() {
		if (function_exists('JB_get_secret_hash')) {
			// since 3.7
			$List = &JBDynamicList::factory('JBPostListMarkup');
			$a = array();
			$List->set_values($a); // list always needs this
			return $List->LMarkup;
            //return JB_get_PostListMarkupObject();
        } elseif (function_exists('JB_get_PostListMarkupObject')) {
			return JB_get_PostListMarkupObject();
		} elseif (function_exists('JB_get_PostListMarkupClass')) {
			return JB_get_PostListMarkupClass();
		} else {
			echo "Warning: The Indeed.com XML back-fill plugin needs Jamit Job Board 3.5.0 or higher. Please disable the plugin and upgrade your software. 202";
		}
	}

	function list_back_fill(&$count, $list_mode) {

		if (!function_exists('JB_get_PostListMarkupObject') && !function_exists('JB_get_PostListMarkupClass')) {

			echo "Warning: The Indeed.com XML back-fill plugin needs Jamit Job Board 3.5.0 or higher. Please disable the plugin and upgrade your software. 515";

			return false;

		}

		if (($list_mode!='ALL') && ($list_mode!='BY_CATEGORY')) return;
		$this->fill_in_progress = true;
		//$i=0;
		
		$i=$count;
		
		$pp_page = JB_POSTS_PER_PAGE;
		if ((sizeof($this->posts)>0) && ($i<$pp_page)) {
			$LM = $this->JB_get_markup_obj(); // load the ListMarkup Class
			$LM->list_day_of_week('<div style="text-align: right;">Job Postings from the Web - <span id=indeed_at><a href="http://www.indeed.com/">jobs</a> by <a
href="http://www.indeed.com/" title="Job Search"><img
src="http://www.indeed.com/p/jobsearch.gif" style="border: 0;
vertical-align: middle;" alt="Indeed job search"></a></span></div>
', 'around_the_web');
			foreach ($this->posts as $post) {
				if ($i>=$pp_page) {
					break;
				}
				$this->list_job($post);
				$i++;
				
			}
		}
		$this->fill_in_progress = false;

	}

/*

The following function is not implemneted.

	function list_pre_fill(&$count, $list_mode) {

		if (($list_mode!='ALL') && ($list_mode!='BY_CATEGORY')) return;
		$this->fill_in_progress = true;

		$pp_page = JB_POSTS_PER_PAGE;
		if ((sizeof($this->posts)>0) && ($i<$pp_page)) {
			$LM = &JB_get_PostListMarkupObject(); // load the ListMarkup Class
			$LM->list_day_of_week('Job Postings from the Web - <span id=indeed_at><a href="http://www.indeed.com/">jobs</a> by <a
href="http://www.indeed.com/" title="Job Search"><img
src="http://www.indeed.com/p/jobsearch.gif" style="border: 0;
vertical-align: middle;" alt="Indeed job search"></a></span>
', 'around_the_web');

			foreach ($this->posts as $post) {
				if ($i>=$pp_page) {
					break;
				}
				$this->list_job($post);
				$i++;
				
			}
		}

		$this->fill_in_progress = false;


	}

	*/


	function list_job(&$post) {

		static $previous_day;

		$this->current_post = $post;

		$LM = &$this->JB_get_markup_obj(); // load the ListMarkup Class

		$count++;

		$POST_MODE = 'normal';		

		$class_name = $LM->get_item_class_name($POST_MODE);
		$class_postfix = $LM->get_item_class_postfix($POST_MODE);

		$DATE = $this->current_post['date'];
		
	    # display day of week
		if (JB_POSTS_SHOW_DAYS_ELAPSED == "YES") {
			//echo $prams['post_date'];

			$day_and_week = JB_get_day_and_week (JB_trim_date($DATE));

			if (JB_trim_date($DATE) !== JB_trim_date($previous_day)) { // new day?
				
				if ($day_and_week!='') {
					$LM->list_day_of_week($day_and_week, $class_postfix);
				}	
			}
			$previous_day = $DATE;

		}

		########################################
		# Open the list data items
		
		$LM->list_item_open($POST_MODE, $class_name);
	   
		########################################################################

		JB_echo_job_list_data($admin); // display the data cells

		########################################################################
		# Close list data items
		$LM->list_item_close();

	}



	function keyword_page() {

		if ($_REQUEST['p']=='IndeedXML') {
			require (dirname(__FILE__).'/keywords.php');
		}

	}

	/**
	 *@deprecated - use JB_does_field_exist() instead
     *
	 */
	 function does_field_exist($table, $field) {
		if (function_exists('JB_does_field_exist')) {
			return JB_does_field_exist($table, $field);
		}
		$result = jb_mysql_query("show columns from `".jb_escape_sql($table)."`");
		while ($row = @JB_mysql_fetch_row($result)) {
			if ($row[0] == $field) {
				return true;
			}
		}

		return false;

	}


	// for the configuration options in Admin:

	function echo_tt_options($value, $type='') {

		if (function_exists('JB_get_DynamicFormObject')) {
			$PForm = JB_get_DynamicFormObject(1);
			$post_tag_to_search = $PForm->get_tag_to_search();
		} else {
			global $post_tag_to_search;
			$post_tag_to_search = JB_get_tag_to_search(1);
		}
	
		require_once (jb_basedirpath()."include/posts.inc.php");

		

		foreach ($post_tag_to_search as $key=>$tag) {
			if ($key !='') {

				$sel ="";
				if (is_array($value)) { // multiple selected

					if (in_array($key, $value)) {
						$sel = ' selected ';
					}

				} else {
					if ($key == $value) {
						$sel = ' selected ';

					}
				}

				if ($type!='') {

					// echo only for the $type

					if ($tag[$key]['field_type'] == $type ) {

						echo '<option  '.$sel.' value="'.$key.'">'.JB_truncate_html_str($tag['field_label'], 50, $foo).'</option>'."\n";
						$output = true;
					}

				} else {

					// echo all
					echo '<option '.$sel.' value="'.$key.'">'.JB_truncate_html_str($tag['field_label'], 50, $foo).'</option>'."\n";
					$output = true;
				}
				
			}
		}

		if ($output == false) {
			echo '<option>[There are no '.$type.' fields to select]</option>';
		}
		
	}

	// Test for PHP bug: http://bugs.php.net/bug.php?id=45996
	function bug_test() {

		$data="<?xml version = '1.0' encoding = 'UTF-8'?>
		<test>
		  &amp;
		</test>
		";

		$parser = xml_parser_create('UTF-8');
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($parser, $data, $vals, $index);
		xml_parser_free($parser);

		if ($vals[0]['value']!='&') {
			return true; // bug detected
		}


	}


	////////////////////////////////////////////

	function get_name() {
		return "Indeed XML Back-fill";

	}

	function get_description() {

		return "Back-fill un-used job posting slots with search results from Indeed.com";
	}

	function get_author() {
		return "Jamit Software";

	}

	function get_version() {
		return "2.2";

	}

	function get_version_compatible() {
		return "3.5.0+";

	}

	# Check the JB_ENABLED_PLUGINS constant to see if this plugin is enabled.
	# Each plugin must have this method implemented in the following way:
	function is_enabled() {

		if (JB_ENABLED_PLUGINS!='') {
			$enabled_plugins = explode(',', JB_ENABLED_PLUGINS);
			if (in_array($this->plugin_name, $enabled_plugins)) {
				return true;
			}
			return false;
		}

	}

	# Enable the plugin. Call the parent class.
	# Each plugin must have this method implemented in the following way:
	function enable() {
		if (!$this->is_enabled()) {

			parent::enable($this->plugin_name);

			if (!$this->does_field_exist('IndeedXML_keywords', 'category_id') ) {

				$sql = "CREATE TABLE `IndeedXML_keywords` (
					  `category_id` int(11) NOT NULL default '0',
					  `kw` varchar(255) NOT NULL default '',
					  `loc` varchar(255) NOT NULL default '',  
					  PRIMARY KEY  (`category_id`)
					) ENGINE=MyISAM";
				jb_mysql_query($sql);
			}


		}

	}

	# Disable the plugin.
	# Each plugin must have this method implemented in the following way:
	function disable() {
		if ($this->is_enabled()) {
			parent::disable($this->plugin_name);
		}
	}

	# display the configuration form
	# You may design your form however you like!
	# Please make sure the it sends the following hidden fields:
	# type="hidden" name="plugin" 
	# type="hidden" name="action" 
	# You can access the config variables like this: $this->config['users_min']
	function config_form() { // 
		 ?>
		<form method="post" action="<?php echo htmlentities($_SERVER['PHP_SELF']); ?>">
		<table border="0" cellpadding="5" cellspacing="2" style="border-style:groove" id="AutoNumber1" width="100%" bgcolor="#FFFFFF">
		
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Publisher ID</b></td>
			<td  bgcolor="#e6f2ea"><input size="20" type="text" name='id' value="<?php echo jb_escape_html($this->config['id']); ?>"> (Your indeed.com publisher ID, get it from https://ads.indeed.com/jobroll/xmlfeed)
			</td>
		</tr>
		<tr>
	

			<td  width="20%" bgcolor="#e6f2ea">
				<b>Country</b></td>
			<td  bgcolor="#e6f2ea"><select  name="c" value="<?php echo $this->config['c']; ?>">
			<option value="us" <?php if ($this->config['c']=="us") echo ' selected '; ?>>US</option>
			<option value="ar" <?php if ($this->config['c']=="ar") echo ' selected '; ?>>Argentina</option>
			<option value="au" <?php if ($this->config['c']=="au") echo ' selected '; ?>>Australia</option>
			<option value="at" <?php if ($this->config['c']=="at") echo ' selected '; ?>>Austria</option>
			<option value="bh" <?php if ($this->config['c']=="bh") echo ' selected '; ?>>Bahrain</option>
			<option value="be" <?php if ($this->config['c']=="be") echo ' selected '; ?>>Belgium</option>
			<option value="br" <?php if ($this->config['c']=="br") echo ' selected '; ?>>Brazil</option>
			<option value="ca" <?php if ($this->config['c']=="ca") echo ' selected '; ?>>Canada</option>
			<option value="cn" <?php if ($this->config['c']=="cn") echo ' selected '; ?>>China</option>
			<option value="co" <?php if ($this->config['c']=="co") echo ' selected '; ?>>Colombia</option>
			<option value="cz" <?php if ($this->config['c']=="cz") echo ' selected '; ?>>Czech Republic</option>
			<option value="dk" <?php if ($this->config['c']=="dk") echo ' selected '; ?>>Denmark</option>
			<option value="fi" <?php if ($this->config['c']=="fi") echo ' selected '; ?>>Finalnd</option>
			<option value="fr" <?php if ($this->config['c']=="fr") echo ' selected '; ?>>France</option>
			<option value="gb" <?php if ($this->config['c']=="gb") echo ' selected '; ?>>Great Britain</option>
			<option value="de" <?php if ($this->config['c']=="de") echo ' selected '; ?>>Germany</option>
			<option value="gr" <?php if ($this->config['c']=="gr") echo ' selected '; ?>>Greece</option>
			<option value="hk" <?php if ($this->config['c']=="hk") echo ' selected '; ?>>Hong Kong</option>
			<option value="hu" <?php if ($this->config['c']=="hu") echo ' selected '; ?>>Hungary</option>
			<option value="in" <?php if ($this->config['c']=="in") echo ' selected '; ?>>India</option>
			<option value="ie" <?php if ($this->config['c']=="ie") echo ' selected '; ?>>Ireland</option>
			<option value="il" <?php if ($this->config['c']=="il") echo ' selected '; ?>>Israel</option>
			<option value="it" <?php if ($this->config['c']=="it") echo ' selected '; ?>>Italy</option>
			<option value="jp" <?php if ($this->config['c']=="jp") echo ' selected '; ?>>Japan</option>
			<option value="kr" <?php if ($this->config['c']=="kr") echo ' selected '; ?>>Korea</option>
			<option value="kw" <?php if ($this->config['c']=="kw") echo ' selected '; ?>>Kuwait</option>
			<option value="lu" <?php if ($this->config['c']=="lu") echo ' selected '; ?>>Luxembourg</option>
			<option value="my" <?php if ($this->config['c']=="my") echo ' selected '; ?>>Malaysia</option>
			<option value="mx" <?php if ($this->config['c']=="mx") echo ' selected '; ?>>Mexico</option>
			<option value="nl" <?php if ($this->config['c']=="nl") echo ' selected '; ?>>Netherlands</option>
			<option value="nz" <?php if ($this->config['c']=="nz") echo ' selected '; ?>>New Zealand</option>
			<option value="no" <?php if ($this->config['c']=="no") echo ' selected '; ?>>Norway</option>
			<option value="om" <?php if ($this->config['c']=="om") echo ' selected '; ?>>Oman</option>
			<option value="pk" <?php if ($this->config['c']=="pk") echo ' selected '; ?>>Pakistan</option>
			<option value="pe" <?php if ($this->config['c']=="pe") echo ' selected '; ?>>Peru</option>
			<option value="ph" <?php if ($this->config['c']=="ph") echo ' selected '; ?>>Philippines</option>
			<option value="pl" <?php if ($this->config['c']=="pl") echo ' selected '; ?>>Poland</option>
			<option value="pt" <?php if ($this->config['c']=="pt") echo ' selected '; ?>>Portugal</option>
			<option value="qa" <?php if ($this->config['c']=="qa") echo ' selected '; ?>>Qatar</option>
			<option value="ro" <?php if ($this->config['c']=="ro") echo ' selected '; ?>>Romainia</option>
			<option value="ru" <?php if ($this->config['c']=="ru") echo ' selected '; ?>>Russia</option>
			<option value="sa" <?php if ($this->config['c']=="sa") echo ' selected '; ?>>Saudi Arabia</option>
			<option value="sg" <?php if ($this->config['c']=="sg") echo ' selected '; ?>>Singapore</option>
			<option value="za" <?php if ($this->config['c']=="za") echo ' selected '; ?>>South Africa</option>
			<option value="es" <?php if ($this->config['c']=="es") echo ' selected '; ?>>Spain</option>
			<option value="se" <?php if ($this->config['c']=="se") echo ' selected '; ?>>Sweden</option>
			<option value="ch" <?php if ($this->config['c']=="ch") echo ' selected '; ?>>Switzerland</option>
			<option value="tw" <?php if ($this->config['c']=="tw") echo ' selected '; ?>>Taiwan</option>
			<option value="tr" <?php if ($this->config['c']=="tr") echo ' selected '; ?>>Turkey</option>
			<option value="ae" <?php if ($this->config['c']=="ae") echo ' selected '; ?>>UAE</option>
			<option value="uk" <?php if ($this->config['c']=="uk") echo ' selected '; ?>>UK</option>
			<option value="ve" <?php if ($this->config['c']=="ve") echo ' selected '; ?>>Venezuela</option>
			</select>
			</td>
		</tr>
		
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Channel</b></td>
			<td  bgcolor="#e6f2ea"><input size="15" type="text" name='ch' value="<?php echo jb_escape_html($this->config['ch']); ?>"> (Optional. Used to track performance if you have more than one web site. Add a new channel in your Indeed publisher account by going to the XML Feed page)
			</td>
		</tr>
		
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Sort</b></td>
			<td  bgcolor="#e6f2ea"><input type="radio" name="so" <?php if ($this->config['so']=='date') { echo ' checked '; } ?> value="date"> By Date Posted (default)<br>
			<input type="radio" name="so" <?php if ($this->config['so']=='relevance') { echo ' checked '; } ?> value="relevance"> By Relevance<br>
			<input type="radio" name="so" <?php if ($this->config['so']=='custom') { echo ' checked '; } ?> value="custom"> By relevance + Date Sorted (Jamit does additional sorting so that the relevant results are sorted by date. CPU intensive)
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Site Type</b></td>
			<td  bgcolor="#e6f2ea"><input type="radio" name="st" <?php if ($this->config['st']=='jobsite') { echo ' checked '; } ?> value="jobsite"> Job Site: To show jobs only from job board sites<br>
			<input type="radio" name="st" <?php if ($this->config['st']=='employer') { echo ' checked '; } ?> value="employer">Show jobs only direct from employer sites<br>
			<input type="radio" name="st" <?php if ($this->config['st']=='') { echo ' checked '; } ?> value="">Show from all<br>
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Job Type</b></td>
			<td  bgcolor="#e6f2ea">
			<input type="radio" name="jt" <?php if ($this->config['jt']=='fulltime') { echo ' checked '; } ?> value="fulltime"> Get Full Time jobs<br>
			<input type="radio" name="jt" <?php if ($this->config['jt']=='parttime') { echo ' checked '; } ?> value="parttime"> Get Part Time jobs<br>
			<input type="radio" name="jt" <?php if ($this->config['jt']=='contract') { echo ' checked '; } ?> value="contract"> Get Contract jobs<br>
			<input type="radio" name="jt" <?php if ($this->config['jt']=='internship') { echo ' checked '; } ?> value="internship"> Get Intership jobs<br>
			<input type="radio" name="jt" <?php if ($this->config['jt']=='temporary') { echo ' checked '; } ?> value="temporary"> Get temporary jobs<br>
			<input type="radio" name="jt" <?php if ($this->config['jt']=='') { echo ' checked '; } ?> value=""> Get all types of jobs
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Radius</b></td>
			<td  bgcolor="#e6f2ea"><input size="3" type="text" name='r' value="<?php echo jb_escape_html($this->config['r']); ?>"> Distance from search location ("as the crow flies"). Default is 25.
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>From Age</b></td>
			<td  bgcolor="#e6f2ea"><input size="3" type="text" name='age' value="<?php echo jb_escape_html($this->config['age']); ?>"> (Number of days back to search. Default/Max is 30)
			</td>
		</tr>
<!--
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>highlight</b></td>
			<td  bgcolor="#e6f2ea"><input type="radio" name="h" <?php if ($this->config['h']=='1') { echo ' checked '; } ?> value="1"> Yes, highlight keywords<br>
			<input type="radio" name="h" <?php if ($this->config['h']=='0') { echo ' checked '; } ?> value="0"> No)
			</td>
		</tr>
	-->
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Filter Results</b></td>
			<td  bgcolor="#e6f2ea"><input type="radio" name="f" <?php if ($this->config['f']=='1') { echo ' checked '; } ?> value="1"> Yes, filter duplicate results<br>
			<input type="radio" name="f" <?php if ($this->config['f']=='0') { echo ' checked '; } ?> value="0"> No
			</td>
		</tr>
	
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>How to Back-fill?</b></td>
			<td  bgcolor="#e6f2ea">
			<input type="radio" name="fill" <?php if ($this->config['fill']=='S') { echo ' checked '; } ?> value="S"> Stop after filling the first page<br>
			<input type="radio" name="fill" <?php if ($this->config['fill']=='C') { echo ' checked '; } ?> value="C"> Continue to futher pages (if more results are available)
			</td>
		</tr>

		
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Main Keyword(s)</b></td>
			<td  bgcolor="#e6f2ea"><input size="20" type="text" name='k' value="<?php echo jb_escape_html($this->config['k']); ?>"> (eg. Title:accounting, By default terms are AND'ed. To see what is possible, use their advanced search page for more possibilities http://www.indeed.com/advanced_search.)
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Main Location</b></td>
			<td  bgcolor="#e6f2ea"><input size="20" type="text" name='l' value="<?php echo jb_escape_html($this->config['l']); ?>"> (Location is optional. e.g. US)
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Search Field(s) for Keyword</b></td>
			<td  bgcolor="#e6f2ea">
			<select name="k_tag[]" multiple size="5">
				<!--<option value="">[Select]</option>-->
				<?php echo $this->echo_tt_options($this->config['k_tag']); ?>
				</select> (The selected search parameters will be combined and used as the keywords for the search query sent to Indeed. If not selected or no keyword is searched, then it will default to the Main Keyword. Hold down the Ctrl key to select/unselect multiple items)
			</td>
		</tr>
		<tr>
			<td  width="20%" bgcolor="#e6f2ea">
				<b>Search Field(s) for Location</b></td>
			<td  bgcolor="#e6f2ea">
			<select name="l_tag[]" multiple size="5" >
				<!--<option value="">[Select]</option>-->
				<?php echo $this->echo_tt_options($this->config['l_tag']); ?>
			</select> (The selected search parameters will be combined and used as the location for the search query sent to Indeed. If not selected or no location is searched, then it will default to the Main Location. Hold down the Ctrl key to select/unselect multiple items)
			</td>
		</tr>
		
		<tr><td colspan="2">Advanced Settings</td>
		</tr>
		<tr>
      <td  bgcolor="#e6f2ea"><font face="Verdana" size="1">Use cURL (Y/N)</font></td>
      <td  bgcolor="#e6f2ea"><font face="Verdana" size="1">
       <br>
	  <input type="radio" name="curl" value="N"  <?php if ($this->config['curl']=='N') { echo " checked "; } ?> >No - Normally this option is best<br>
	  <input type="radio" name="curl" value="Y"  <?php if ($this->config['curl']=='Y') { echo " checked "; } ?> >Yes - If your hosting company blocked fsockopen() and has cURL, then use this option</font></td>
    </tr>

	<tr>
      <td  bgcolor="#e6f2ea"><font face="Verdana" size="1">cURL 
      Proxy URL</font></td>
      <td  bgcolor="#e6f2ea"><font face="Verdana" size="1">
      <input type="text" name="proxy" size="50" value="<?php echo $this->config['proxy']; ?>">Leave blank if your server does not need one. Contact your hosting company if you are not sure about which option to use. For GoDaddy it is: http://proxy.shr.secureserver.net:3128<br></font></td>
    </tr>
		<tr>
			<td  bgcolor="#e6f2ea" colspan="2"><font face="Verdana" size="1"><input type="submit" value="Save">
		</td>
		</tr>
		</table>
		<input type="hidden" name="plugin" value="<?php echo jb_escape_html($_REQUEST['plugin']);?>">
		<input type="hidden" name="action" value="save">

		</form>
		<?php
		if ($this->bug_test()) {
			echo "<p><font color='red'>PHP Bug warning: The system detected that your PHP version has a bug in the XML parser. This is not a bug in the Jamit Job Board, but a bug in 'libxml' that comes built in to PHP itself. An upgrade of PHP with the latest version of 'libxml' with  is recommended. This plugin contains a workaround for this bug - so it should still work...</font> For details about the bug, please see <a href='http://bugs.php.net/bug.php?id=45996'>http://bugs.php.net/bug.php?id=45996</a></p> ";

		}
		// check if fsockopen is disabled
		if (stristr(ini_get('disable_functions'), "fsockopen")) {
			JB_pp_mail_error ( "<p>fsockopen is disabled on this server. You can try to set this plugin to use cURL instead</p>");
		
		}
		?>
		<b>Important:</b> After configuring Go here to <a href="p.php?p=IndeedXML&action=kw">Configure Category Keywords</a>
<p>
TROUBLE SHOOTING
<p>
> Keywords do not return any results?
Try your keyword on indeed.com first, before putting them in the job board.
<p>
> Page times out / does not fetch any results?
Your server must be able to make external connections to api.indeed.com
through port 80 (HTTP). This means that fsockopen must be enabled on
your host, and must be allowed to make external connections.
<p>
- I see warning/errors messages saying that 'argument 2' is missing.
This has been reported and can be fixed if you open the include/lists.inc.php
file and locate the following code:
<p>
JBPLUG_do_callback('job_list_data_val', $val, $template_tag);
<p>
and change to:
<p>
JBPLUG_do_callback('job_list_data_val', $val, $template_tag, $a);
<p>
- Can I make the links open in a new window?
<p>
Nope.. Indeed rules are that in order to record the click, it must use their 
onmousedown event to call their javascript, and the javascripts 
prevents the link from opening in a new window.
<p>
- It still does not work
<p>
Please check the requirements - requires Jamit Job Board 3.5.0 or higher
Please also check with your hosting company that your server
is allowed to use fsockopen or Curl
		 <?php

	}

	# save the values from your config form
	# The values will be serialized and saved in config.php
	# After the $this->plugin_name parameter, enter the list of variables like this:

	function save_config() {
		if (is_array($_REQUEST['l_tag'])) {
			$_REQUEST['l_tag'] = implode(',',$_REQUEST['l_tag']);
		}
		if (is_array($_REQUEST['k_tag'])) {
			$_REQUEST['k_tag'] = implode(',',$_REQUEST['k_tag']);
		}

		# JBPLUG_save_config_variables ( string $class_name [, string $field_name [, string $...]] )
		JBPLUG_save_config_variables($this->plugin_name, 'priority', 'l', 'k', 'ch', 'id', 'l_tag', 'k_tag', 's', 'curl', 'proxy', 'fill', 'c', 'f', 'age', 'h', 'r', 'st', 'so', 'jt' );
	}

	function get_cache_dir() {

		if (function_exists('JB_get_cache_dir')) {
			return JB_get_cache_dir();
		} else {
			static $dir;
			if (isset($dir)) return $dir;
			
			$dir = dirname(__FILE__);
			$dir = preg_split ('%[/\\\]%', $dir);
			$blank = array_pop($dir);
			$blank = array_pop($dir);
			$blank = array_pop($dir);
			$dir = implode('/', $dir).'/cache/';
			JBPLUG_do_callback('get_cache_dir', $dir);
			return $dir;

		}

	}

}

$_JB_PLUGINS['IndeedXML'] = new IndeedXML; // add a new instance of the class to the global plugins array
?>