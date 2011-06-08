<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Stash {

	public $EE;
	public $site_id;
	
	private $_stash;
	private $_stash_cookie	= 'stashid';
	private $_session_id;

	/*
	 * Constructor
	 */
	public function __construct()
	{
		$this->EE = get_instance();
		
		// load dependencies
		$this->EE->lang->loadfile('stash');
		$this->EE->load->model('stash_model');

		 // site id
		$this->site_id = $this->EE->config->item('site_id');
		
		// stash type, default to 'variable'
		$type = strtolower( $this->EE->TMPL->fetch_param('type', 'variable') );
		
		// create a stash array in the session if we don't have one
		if ( ! array_key_exists('stash', $this->EE->session->cache) )
		{
			// create a stash array in the session if we don't have one
			$this->EE->session->cache['stash'] = array();
		}
		
		// determine the stash type
		if ($type === 'variable')
		{
			// we're setting/getting a variable
			$this->_stash =& $this->EE->session->cache['stash'];
		}
		elseif ($type === 'snippet')
		{
			// we're setting/getting a global {snippet}
			$this->_stash =& $this->EE->config->_global_vars;
		}
		else
		{
			$this->EE->output->show_user_error('general', $this->EE->lang->line('unknown_stash_type'));
		}

		// fetch the stash session id
		if ( ! isset($this->EE->session->cache['stash']['_session_id']) )
		{	
			// cleanup - delete ANY last activity records older than 2 hours
			$this->EE->stash_model->prune_last_activity(7200);

			// do we have a session cookie?	
			if ( ! $this->EE->input->cookie($this->_stash_cookie) )
			{ 
				// NO cookie - let's generate a unique id
				$unique_id = $this->EE->functions->random();
				
				// add to stash array
				$this->EE->session->cache['stash']['_session_id'] = $unique_id;
				
				// create a cookie, set to 2 hours
				$this->EE->functions->set_cookie($this->_stash_cookie, $unique_id, 7200);
			}
			else
			{	
				// YES - cookie exists
				$this->EE->session->cache['stash']['_session_id'] = $this->EE->input->cookie($this->_stash_cookie);

				// get the last activity
				if ( $last_activity = $this->EE->stash_model->get_last_activity_date(
						$this->EE->session->cache['stash']['_session_id'], 
						$this->site_id
				))
				{
					// older than 5 minutes? Let's regenerate the cookie and update the db
					if ( $last_activity + 300 < $this->EE->localize->now)
					{			
						// overwrite cookie
						$this->EE->functions->set_cookie($this->_stash_cookie, $this->EE->session->cache['stash']['_session_id'], 7200);

						// update db last activity record for this session id
						$this->EE->stash_model->update_last_activity(
							$this->EE->session->cache['stash']['_session_id'],
							$this->site_id
						);	
					}
				}
				else
				{
					// no last activity exists, let's create a record for this session id
					$this->EE->stash_model->insert_last_activity(
						$this->EE->session->cache['stash']['_session_id'],
						$this->site_id,
						$this->EE->session->userdata['ip_address'].'|'.$this->EE->session->userdata['user_agent']
					);
				}					
			}
		}
		
		// create a reference to the session id
		$this->_session_id =& $this->EE->session->cache['stash']['_session_id'];
	}

	// ---------------------------------------------------------
	
	/**
	 * Set content in the session (partial) or in the EE instance (snippet). 
	 * Optionally save to the database
	 *
	 * @access public
	 * @param bool 	 $update Update an existing stashed variable
	 * @param bool 	 $append Append or prepend to existing variable
	 * @return void 
	 */
	public function set($update = FALSE, $append = TRUE)
	{	
		/* Sample use
		---------------------------------------------------------
		{exp:stash:set name="title" type="snippet"}A title{/exp:stash:set}
		--------------------------------------------------------- */
		
		// do we want to parse any tags inside tagdata?	
		if ( $parse_tags = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('parse_tags')))
		{	
			$this->EE->TMPL->log_item("Stash: processing inner tags");
					
			$TMPL2 = $this->EE->TMPL;
			unset($this->EE->TMPL);
			
			$this->EE->TMPL = new EE_Template();
			$this->EE->TMPL->start_microtime = $TMPL2->start_microtime;
			$this->EE->TMPL->template = $TMPL2->tagdata;
			$this->EE->TMPL->tag_data	= array();
			$this->EE->TMPL->var_single = array();
			$this->EE->TMPL->var_cond	= array();
			$this->EE->TMPL->var_pair	= array();
			$this->EE->TMPL->plugins = $TMPL2->plugins;
			$this->EE->TMPL->modules = $TMPL2->modules;
			$this->EE->TMPL->parse_tags();
			$this->EE->TMPL->process_tags();
			$this->EE->TMPL->loop_count = 0;
			
			$TMPL2->tagdata = $this->EE->TMPL->template;
			$TMPL2->log = array_merge($TMPL2->log, $this->EE->TMPL->log);

			foreach (get_object_vars($TMPL2) as $key => $value)
			{
				$this->EE->TMPL->$key = $value;
			}
			
			$this->EE->TMPL = $TMPL2;	
			unset($TMPL2);	
		}
		
		// do we want this tag to return it's tagdata?
		$output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output'));
		
		if ( !! $name = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{		
			// get params
			$label 			= strtolower($this->EE->TMPL->fetch_param('label', $name));
			$save 			= (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('save'));						
			$refresh 		= $this->EE->TMPL->fetch_param('refresh', 1440); // minutes (1440 = 1 day)	
			$scope 			= strtolower($this->EE->TMPL->fetch_param('scope', 'user')); // user|site
			$match 			= $this->EE->TMPL->fetch_param('match', NULL); // regular expression to filter value by
			$against 		= $this->EE->TMPL->fetch_param('against', $this->EE->TMPL->tagdata); // text to apply filter against
			
			// apply filter
			if ( $match !== NULL && preg_match('/^#(.*)#$/', $match))
			{	
				$match = $this->EE->security->entity_decode($match);
				if ( ! preg_match($match, $against))
				{
					return;
				}
			}

			if ( $update === TRUE )
			{
				// We're updating a variable, so lets see if it's in the session or db
				$this->_stash[$name] = $this->get();
			
				// Append or prepend?
				if ( $append )
				{
					$this->_stash[$name] .= $this->EE->TMPL->tagdata;
				}
				else
				{
					$this->_stash[$name] = $this->EE->TMPL->tagdata.$this->_stash[$name];
				}
			} 
			else
			{
				$this->_stash[$name] = $this->EE->TMPL->tagdata;
			}
			
			if ($save)
			{	
				// clean data for inserting
				$parameters = $this->EE->security->xss_clean($this->_stash[$name]);
				
				// what's the intended variable scope? 
				if ($scope === 'site')
				{
					// GLOBAL scope - (scope = 'site')
					// we don't want the overhead of updating the record if it already exists
					// so let's check a record exists for this key
					if ( ! $result = $this->EE->stash_model->get_key($name, '_global', $this->site_id, 'id'))
					{
						// no record, so insert one
						$this->EE->stash_model->insert_key(
							$name,
							'_global',
							$this->site_id,
							$this->EE->localize->now + ($refresh * 60),
							$parameters,
							$label
						);
					}
				}
				else
				{
					// USER scope (scope = 'user')		
					// let's check if there is an existing record, and that that it matches the new one exactly
					if ( $result = $this->EE->stash_model->get_key($name, $this->_session_id, $this->site_id))
					{
						// record exists, but is it identical?
						if ( $result !== $parameters)
						{
							// nope - update
							$this->EE->stash_model->update_key(
								$name,
								$this->_session_id,
								$this->site_id,
								$this->EE->localize->now + ($refresh * 60),
								$parameters
							);
						}
					}
					else
					{
						// no record - insert one
						$this->EE->stash_model->insert_key(
							$name,
							$this->_session_id,
							$this->site_id,
							$this->EE->localize->now + ($refresh * 60),
							$parameters,
							$label
						);
					}
				}		
			}
		}
		else
		{
			// no name supplied, so let's assume we want to set sections of content within tag pairs
			// {stash:my_variable}...{/stash:my_variable}
			$vars = array();
			$tagdata = $this->EE->TMPL->tagdata;
			
			foreach($this->EE->TMPL->var_pair as $key => $val)
			{
				if (strncmp($key, 'stash:', 6) ==  0)
				{
					$pattern = '/{'.$key.'}(.*){\/'.$key.'}/Usi';
					preg_match($pattern, $tagdata, $matches);
					if (!empty($matches))
					{
						// set the variable, but cleanup first in case there are any nested tags
						$this->EE->TMPL->tagparams['name'] = str_replace('stash:', '', $key);
						$this->EE->TMPL->tagdata = preg_replace('/{stash:[a-zA-Z0-9-_]+}(.*){\/stash:[a-zA-z0-9]+}/Usi', '', $matches[1]);
						$this->EE->TMPL->tagparams['parse_tags'] = 'no';
						$this->set();
					}	
				}
			}
			
			// reset tagdata to original value
			$this->EE->TMPL->tagdata = $tagdata;
			unset($tagdata);
		}
		
		if ($output)
		{
			return $this->EE->TMPL->tagdata;
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Get content from session, database cache or $_POST/$_GET superglobal
	 *
	 * @access public
	 * @param  string 	 $name  The name of the variable to retrieve
	 * @param  string 	 $type  The type of variable
	 * @param  string 	 $scope The scope of the variable
	 * @return string 
	 */
	public function get($name='', $type='variable', $scope='user')
	{		
		/* Sample use
		---------------------------------------------------------
		{exp:stash:get name="title"}
		
		OR static call within PHP enabled templates: 
		<?php echo stash::get('title') ?>
		--------------------------------------------------------- */
		
		// is this method being called statically from PHP?
		if (func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
		{
			$this->EE->TMPL->tagparams['name']    = $name;
			$this->EE->TMPL->tagparams['type']    = $type;
			$this->EE->TMPL->tagparams['scope']   = $scope;
		
			// as this function is called statically, 
			// we need to get an instance of this object and run get()
			$self = new self();	
			return $self->get();
		}

		$name = strtolower($this->EE->TMPL->fetch_param('name'));
		$default = strtolower($this->EE->TMPL->fetch_param('default', '')); // default value
		$dynamic = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('dynamic'));		
		$scope = strtolower($this->EE->TMPL->fetch_param('scope', 'user')); // user|site
		
		// sanitize/filter retrieved variables? 
		// useful for user submitted data in superglobals - but don't do this by default!
		$strip_tags = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_tags'));	
		$strip_curly_braces = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_curly_braces'));	
		
		// do we want this tag to return the value, or just set the variable quietly in the background?
		$output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output', 'yes'));
		
		$value = NULL;

		// Let's see if it's been stashed before
		if ( array_key_exists($name, $this->_stash) )
		{
			$value = $this->_stash[$name];			
		}
		else
		{
			// Are we looking for a superglobal or uri segement?
			if ( $dynamic )
			{
				// is it in the $_POST or $_GET superglobals ( run through xss_clean() )?
				if ( ! $from_global = $this->EE->input->get_post($name, TRUE) )
				{
					// no, so let's check the uri segments
					$segs = $this->EE->uri->segment_array();

					foreach ( $segs as $index => $segment )
					{
					    if ( $segment == $name && array_key_exists( ($index+1), $segs) )
						{
							$from_global = $segs[($index+1)];
							break;
						}
					}
				}
				
				if ( !! $from_global )
				{
					// save to stash, and optionally to database, if save="yes"
					$value = $from_global;
					$this->EE->TMPL->tagparams['name'] = $name;
					$this->EE->TMPL->tagdata = $value;
					$this->set();
				}
			}	
			
			// Not found in globals, so let's look in the database table cache
			if ( $value == NULL)
			{		
				// cleanup keys with expiry date older than right now 
				$this->EE->stash_model->prune_keys();
					
				// narrow the scope to user?
				$session_id = $scope === 'user' ? $this->_session_id : '';
						
				// look for our key
				if ($parameters = $this->EE->stash_model->get_key(
					$name, 
					$session_id, 
					$this->site_id
				))
				{
					// save to session 
					$value = $this->_stash[$name] = $parameters;
				}
				else
				{
					// set default value
					$value = $default; // note: $default value is '' unless set as a parameter
					
					if ( $value !== '')
					{
						$this->EE->TMPL->tagparams['name'] = $name;
						$this->EE->TMPL->tagdata = $value;
						$this->set(); 
					}
				}
			}
		}			 
		
		// output
		if ($output)
		{
			// strip tags?
			if ($strip_tags)
			{
				$value = strip_tags($value);
			}
		
			// strip curly braces?
			if ($strip_curly_braces)
			{
				$value = str_replace(array(LD, RD), '', $value);
			}

			return $value;
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Checks if a variable is empty or non-existent, 
	 * handy for conditionals
	 *
	 * @access public
	 * @return integer
	 */
	public function not_empty()
	{
		$value  = str_replace( array("\t", "\n", "\r", "\0", "\x0B"), '', trim( $this->get() ));
		return empty( $value ) ? 0 : 1;
	}
	
	
	// ---------------------------------------------------------
	
	/**
	 * Append the specified value to an already existing variable.
	 *
	 * @access public
	 * @return void 
	 */
	public function append()
	{
		return $this->set(TRUE, TRUE);
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Prepend the specified value to an already existing variable.
	 *
	 * @access public
	 * @return void 
	 */
	public function prepend()
	{
		return $this->set(TRUE, FALSE);
	}
		
	// ---------------------------------------------------------
	
	/**
	 * Single tag version of set(), for when you need to use a 
	 * plugin as a tag parameter (always use with parse="inward")
	 * 
	 *
	 * @access public
	 * @param bool 	 $update Update an existing stashed variable
	 * @param bool 	 $append Append or prepend to existing variable
	 * @return void 
	 */
	public function set_value($update = FALSE, $append = TRUE)
	{	
		/* Sample use
		---------------------------------------------------------
		{exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}
		--------------------------------------------------------- */
		if ( !! $this->EE->TMPL->tagdata = $this->EE->TMPL->fetch_param('value', FALSE))
		{
			return $this->set($update, $append);
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Single tag version of append()
	 *
	 * @access public
	 * @return void 
	 */
	public function append_value()
	{
		return $this->set_value(TRUE, TRUE);
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Single tag version of prepend()
	 *
	 * @access public
	 * @return void 
	 */
	public function prepend_value()
	{
		return $this->set_value(TRUE, FALSE);
	}	
	
	// ---------------------------------------------------------
	
	/**
	 * Bundle up a selection of variables
	 * Note that we don't want bundled variables to expire 
	 *
	 * @access public
	 * @return void 
	 */
	public function bundle()
	{
		/* Sample use
		---------------------------------------------------------
		{exp:stash:bundle name="contact_form" unique="no"}
			{stash:contact_name}Your name{/stash:contact_name}
			{stash:contact_email}mcroxton@hallmark-design.co.uk{/stash:contact_email}
		{/exp:stash:bundle}
		--------------------------------------------------------- */
		
		if ( !! $bundle = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{
			// get params
			$bundle_label = strtolower($this->EE->TMPL->fetch_param('label', $bundle));
			$unique = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('unique'));	
			
			// let's build an array of key => value pairs
			$vars = array();
			
			// loop through tag pairs {stash:variable_name}my value{/stash:variable_name}
			$first_var = false;
			$bundle_entry_label = $bundle;
			
			foreach($this->EE->TMPL->var_pair as $key => $val)
			{
				$pattern = '/{'.$key.'}(.*){\/'.$key.'}/Usi';
				preg_match($pattern, $this->EE->TMPL->tagdata, $matches);
				if (!empty($matches))
				{
					$name = str_replace('stash:', '', $key);
					$vars[$name] = $matches[1];
					
					// if this is the first variable in the bundle, use it as the name for this bundle entry row 
					// (imagine looking at a bundle of paper, it's the one on top)
					if ( ! $first_var)
					{
						// truncate text and make safe 
						$bundle_entry_label = $this->EE->security->xss_clean(substr($matches[1], 0, 64));
						$first_var = TRUE;
					}
				}	
			}
			
			// Does this bundle already exist? Let's try to get it's id
			if ( ! $bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle, $this->site_id))
			{
				// doesn't exist, let's create it
				$bundle_id = $this->EE->stash_model->insert_bundle(
					$bundle,
					$this->site_id,
					$bundle_label
				);
				
				// bundle must be unique so make sure we disable unique checks
				$unique = FALSE;
			}
			
			if ($unique)
			{
				if ($this->EE->stash_model->bundle_entry_exists($bundle_id, $this->site_id))
				{
					// error
					$this->EE->output->show_user_error('general', sprintf($this->EE->lang->line('bundle_entry_exists'), $bundle));
				}
			}
		
			// now stash our bundled data
			$this->EE->stash_model->insert_key(
				'_bundle',
				$this->_session_id,
				$this->site_id,
				0,
				serialize($vars),
				$bundle_entry_label,
				$bundle_id
			);
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Flush the variables database cache for the current site (Super Admins only)
	 *
	 * @access public
	 * @return string 
	 */
	public function flush_cache()
	{
		if ($this->EE->session->userdata['group_title'] == "Super Admins")
		{
			$this->EE->stash_model->flush_cache($this->site_id);
			return $this->EE->lang->line('cache_flush_success');
		}
		else
		{
			// not authorised
			$this->EE->output->show_user_error('general', $this->EE->lang->line('not_authorized'));
		}
	}
	
}
/* End of file mod.stash.php */
/* Location: ./system/expressionengine/third_party/stash/mod.stash.php */