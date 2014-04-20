<?php

function elgg_solr_reindex() {
	set_time_limit(0);
	
	// lock the function
	elgg_set_plugin_setting('reindex_running', 1, 'elgg_solr');

	$debug = get_input('debug', false);
	if ($debug) {
		elgg_set_config('elgg_solr_debug', 1);
	}

	$registered_types = get_registered_entity_types();

	$ia = elgg_set_ignore_access(true);

	elgg_set_config('elgg_solr_nocommit', true); // tell our indexer not to commit right away

	$count = 0;
	foreach ($registered_types as $type => $subtypes) {
		$options = array(
			'type' => $type,
			'limit' => false
		);

		if ($subtypes) {
			$options['subtypes'] = $subtypes;
		}

		$batch_size = elgg_get_plugin_setting('reindex_batch_size', 'elgg_solr');
		$entities = new ElggBatch('elgg_get_entities', $options, null, $batch_size);

		foreach ($entities as $e) {

			$count++;
			if ($count % 100) {
				elgg_set_config('elgg_solr_nocommit', false); // push a commit on this one
			}
			elgg_solr_add_update_entity(null, null, $e);

			elgg_set_config('elgg_solr_nocommit', true);
		}
	}
	
	// index comments
	$options = array(
		'annotation_name' => 'generic_comment',
		'limit' => false
	);
	$comments = new ElggBatch('elgg_get_annotations', $options, null, $batch_size);
	
	foreach ($comments as $comment) {
		$count++;
		if ($count % 100) {
			elgg_set_config('elgg_solr_nocommit', false); // push a commit on this one
		}
		elgg_solr_add_update_annotation(null, null, $comment);

		elgg_set_config('elgg_solr_nocommit', true);
	}

	if ($debug) {
		elgg_solr_debug_log($count . ' entities sent to Solr');
	}
	
	elgg_solr_push_doc('<commit/>'); // commit the last of the entities
	elgg_set_ignore_access($ia);
	elgg_set_plugin_setting('reindex_running', 0, 'elgg_solr');
}


function elgg_solr_get_client() {
	elgg_load_library('Solarium');
	
	Solarium_Autoloader::register();
	
	$options = elgg_solr_get_adapter_options();

	$config = array('adapteroptions' => $options);

	// create a client instance
	return new Solarium_Client($config);
}


function elgg_solr_get_adapter_options() {
	return array(
		'host' => elgg_get_plugin_setting('host', 'elgg_solr'),
		'port' => elgg_get_plugin_setting('port', 'elgg_solr'),
		'path' => elgg_get_plugin_setting('solr_path', 'elgg_solr'),
		'core' => elgg_get_plugin_setting('solr_core', 'elgg_solr'),
	);
}


function elgg_solr_has_settings() {
	$host = elgg_get_plugin_setting('host', 'elgg_solr');
	$port = elgg_get_plugin_setting('port', 'elgg_solr');
	$path = elgg_get_plugin_setting('path', 'elgg_solr');
	
	if (empty($host) || empty($port) || empty($path)) {
		return false;
	}
	
	return true;
}



/**
 * get default filter queries based on search params
 * 
 * @param type $params
 * 
 * return array
 */
function elgg_solr_get_default_fq($params) {
	$fq = array();
	
	// type/types
	if (isset($params['type']) && $params['type'] !== ELGG_ENTITIES_ANY_VALUE) {
		if ($params['type'] === ELGG_ENTITIES_NO_VALUE) {
			$fq['type'] = '-type:[* TO *]';
		}
		else {
			$fq['type'] = 'type:' . $params['type'];
		}
	}
	
	if ($params['types'] && $params['types'] !== ELGG_ENTITIES_ANY_VALUE) {
		if (is_array($params['types'])) {
			$fq['type'] = 'type:(' . implode(' OR ', $params['types']) . ')';
		}
		else {
			if ($params['types'] === ELGG_ENTITIES_NO_VALUE) {
				$fq['type'] = '-type:[* TO *]';
			}
			else {
				$fq['type'] = 'type:' . $params['types'];
			}
		}
	}
	
	//subtype
	if (isset($params['subtype']) && $params['subtype'] !== ELGG_ENTITIES_ANY_VALUE) {
		if ($params['subtype'] === ELGG_ENTITIES_NO_VALUE) {
			$fq['subtype'] = '-subtype:[* TO *]';
		}
		else {
			$fq['subtype'] = 'subtype:' . $params['subtype'];
		}
	}
	
	if (isset($params['subtypes']) && $params['subtypes'] !== ELGG_ENTITIES_ANY_VALUE) {
		if (is_array($params['subtypes'])) {
			$fq['subtype'] = 'subtype:(' . implode(' OR ', $params['subtypes']) . ')';
		}
		else {
			if ($params['subtypes'] === ELGG_ENTITIES_NO_VALUE) {
				$fq['subtype'] = '-subtype[* TO *]';
			}
			else {
				$fq['subtype'] = 'subtype:' . $params['subtypes'];
			}
		}
	}
	
	
	//container
	if (isset($params['container_guid']) && $params['container_guid'] !== ELGG_ENTITIES_ANY_VALUE) {
			$fq['container'] = 'container_guid:' . $params['container_guid'];
	}
	
	if (isset($params['container_guids'])&& $params['container_guids'] !== ELGG_ENTITIES_ANY_VALUE) {
		if (is_array($params['container_guids'])) {
			$fq['container'] = 'container_guid:(' . implode(' OR ', $params['container_guids']) . ')';
		}
		else {
				$fq['container'] = 'container_guid:' . $params['container_guid'];
		}
	}
	
	//owner
	if (isset($params['owner_guid']) && $params['owner_guid'] !== ELGG_ENTITIES_ANY_VALUE) {
		$fq['owner'] = 'owner_guid:' . $params['owner_guid'];
	}
	
	if (isset($params['owner_guids']) && $params['owner_guids'] !== ELGG_ENTITIES_ANY_VALUE) {
		if (is_array($params['owner_guids'])) {
			$fq['owner'] = 'owner_guid:(' . implode(' OR ', $params['owner_guids']) . ')';
		}
		else {
				$fq['owner'] = 'owner_guid:' . $params['owner_guid'];
		}
	}
	
	$access_query = elgg_solr_get_access_query();
	if ($access_query) {
		$fq['access'] = $access_query;
	}

	return $fq;
}



/**
 * Register a function to define specific configuration of an entity in solr
 * 
 * @param type $type - the entity type
 * @param type $subtype - the entity subtype
 * @param type $function - the function to call for updating an entity in solr
 */
function elgg_solr_register_solr_entity_type($type, $subtype, $function) {
	$solr_entities = elgg_get_config('solr_entities');
	
	if (!is_array($solr_entities)) {
		$solr_entities = array();
	}
	
	$solr_entities[$type][$subtype] = $function;
	
	elgg_set_config('solr_entities', $solr_entities);
}


/**
 * 
 * 
 * @param type $type
 * @param type $subtype
 * @return boolean
 */
function elgg_solr_get_solr_function($type, $subtype) {
	if (elgg_get_config('elgg_solr_debug')) {
		$debug = true;
	}
	
	$solr_entities = elgg_get_config('solr_entities');
	
	if (is_callable($solr_entities[$type][$subtype])) {
		return $solr_entities[$type][$subtype];
	}
	
	if (is_callable($solr_entities[$type]['default'])) {
		return $solr_entities[$type]['default'];
	}
	
	if (is_callable($solr_entities['entity']['default'])) {
		return $solr_entities['entity']['default'];
	}
	
	if ($debug) {
		elgg_solr_debug_log('Solr function not callable for type: ' . $type . ', subtype: ' . $subtype);
	}
	
	return false;
}


function elgg_solr_add_update_file($entity) {
	$debug = false;
	if (elgg_get_config('elgg_solr_debug')) {
		$debug = true;
	}
	
	// combine some additional sources to search
	$desc = $entity->description;
	
	$owner = $entity->getOwnerEntity();

    $title       = urlencode($entity->title);
    $description = urlencode($desc);
   

    // File you want to upload/post
	$extract = elgg_get_plugin_setting('extract_handler', 'elgg_solr');
	if (file_exists($entity->getFilenameOnFilestore()) && $extract == 'yes') {
		$options = elgg_solr_get_adapter_options();
		
		if ($options['core']) {
			$options['core'] .= '/';
		}
		
		// URL on which we have to post data
		$url = "http://{$options['host']}:{$options['port']}{$options['path']}{$options['core']}update/extract?"
         . "literal.id={$entity->guid}"
         . "&literal.container_guid={$entity->container_guid}"
		 . "&literal.owner_guid={$entity->owner_guid}"
		 . "&literal.title={$title}"
		 . "&literal.type=object"
		 . "&literal.subtype=file"
		 . "&literal.access_id={$entity->access_id}"
		 . "&literal.time_created={$entity->time_created}";

		if ($description) {
			$url .= "&literal.description={$description}";
		}
		
		$valid_tag_names = elgg_get_registered_tag_metadata_names();
		
		if ($valid_tag_names && is_array($valid_tag_names)) {
			foreach ($valid_tag_names as $tagname) {
				$tags = $entity->$tagname;
				if ($tags && !is_array($tags)) {
					$tags = array($tags);
				}
		
				if ($tags && is_array($tags)) {
					foreach ($tags as $tag) {
						$t = urlencode($tagname . '%%' . $tag);
						$url .= "&literal.tags={$t}";
					}
				}
			}
		}

		$url .= "&uprefix=attr_&fmap.content=attr_content";
		
		if (!elgg_get_config('elgg_solr_nocommit')) {
			$url .= '&commit=true';
		}
		
		$ch = curl_init();
		$doc = array('myfile' => '@'.$entity->getFilenameOnFilestore());

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $doc);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		
		// Execute the request
		try {
			if ($debug) {
				elgg_solr_debug_log('attempting solr update with url: ' . $url);
				elgg_solr_debug_log('doc = ' . print_r($doc,1));
			}
			$response = curl_exec($ch);
		
			if ($debug) {
				elgg_solr_debug_log('curl response:  ' . print_r($response,1));
			}
		} catch( Exception $e) {
			if ($debug) {
				elgg_solr_debug_log('elgg_solr_add_update_object() - ' . $e->getMessage());
			}
		}

		return true;
	}
	
	$title       = elgg_solr_xml_format($entity->title);
    $description = elgg_solr_xml_format($desc);

	// we have no file to send, so push xml doc
	$doc = <<<EOF
        <add>
            <doc>
                <field name="id">{$entity->guid}</field>
				<field name="title">{$title}</field>
				<field name="description">{$description}</field>
                <field name="type">object</field>
				<field name="subtype">file</field>
				<field name="container_guid">{$entity->container_guid}</field>
				<field name="owner_guid">{$entity->owner_guid}</field>
				<field name="access_id">{$entity->access_id}</field>
				<field name="time_created">{$entity->time_created}</field>
EOF;
		
	$valid_tag_names = elgg_get_registered_tag_metadata_names();
		
	if ($valid_tag_names && is_array($valid_tag_names)) {
			foreach ($valid_tag_names as $tagname) {
				$tags = $entity->$tagname;
				if ($tags && !is_array($tags)) {
					$tags = array($tags);
				}
				if ($tags && is_array($tags)) {
					foreach ($tags as $tag) {
						$t = elgg_solr_xml_format($tagname . '%%' . $tag);
							$doc .= <<<EOF
				<field name="tags">{$t}</field>
EOF;
					}
				}
			}
	}
				
$doc .= <<<EOF
            </doc>
        </add>
EOF;
				
	elgg_solr_push_doc($doc);
}


function elgg_solr_add_update_object_default($entity) {
	
	if (!is_registered_entity_type($entity->type, $entity->getSubtype())) {
		return false;
	}
	
	$title       = elgg_solr_xml_format($entity->title);
    $description = elgg_solr_xml_format($entity->description);

    // Build the user document to be posted
    $doc = <<<EOF
        <add>
            <doc>
				<field name="id">{$entity->guid}</field>
				<field name="title">{$title}</field>
				<field name="description">{$description}</field>
                <field name="type">object</field>
				<field name="subtype">{$entity->getSubtype()}</field>
				<field name="access_id">{$entity->access_id}</field>
				<field name="container_guid">{$entity->container_guid}</field>
				<field name="owner_guid">{$entity->owner_guid}</field>
				<field name="time_created">{$entity->time_created}</field>
EOF;
				
	$valid_tag_names = elgg_get_registered_tag_metadata_names();
	
	if ($valid_tag_names && is_array($valid_tag_names)) {
		foreach ($valid_tag_names as $tagname) {
			$tags = $entity->$tagname;
			if ($tags && !is_array($tags)) {
				$tags = array($tags);
			}
			if ($tags && is_array($tags)) {
				foreach ($tags as $tag) {
					$t = elgg_solr_xml_format($tagname . '%%' . $tag);
						$doc .= <<<EOF
			<field name="tags">{$t}</field>
EOF;
				}
			}
		}
	}


$doc .= <<<EOF
            </doc>
        </add>
EOF;

	elgg_solr_push_doc($doc);
}


function elgg_solr_add_update_user($entity) {
	$debug = false;
	if (elgg_get_config('elgg_solr_debug')) {
		$debug = true;
	}
	
	if (!elgg_instanceof($entity, 'user')) {
		if ($debug) {
			elgg_solr_debug_log('Error: Not a valid user - ' . print_r($entity,1));
		}
		return false;
	}
	
	if (!is_registered_entity_type($entity->type, $entity->getSubtype())) {
		if ($debug) {
			elgg_solr_debug_log('Error: Not a valid entity type - ' . print_r($entity,1));
		}
		return false;
	}
	
    $guid     = $entity->guid;
    $name     = elgg_solr_xml_format($entity->name);
	$username = elgg_solr_xml_format($entity->username);
	
	
	// lump public profile fields in with description
	$profile_fields = elgg_get_config('profile_fields');
	$desc = '';
	if (is_array($profile_fields) && sizeof($profile_fields) > 0) {
		$walled = elgg_get_config('walled_garden');
		foreach ($profile_fields as $shortname => $valtype) {
			$md = elgg_get_metadata(array(
				'guid' => $entity->guid,
				'metadata_names' => array($shortname)
			));
			
			foreach ($md as $m) {
				if ($m->access_id == ACCESS_PUBLIC || ($walled && $m->access_id == ACCESS_LOGGED_IN)) {
					$desc .= $m->value . ' ';
				}
			}
		}
	}
	
	$description = elgg_solr_xml_format($desc);

    // Build the user document to be posted
    $doc = <<<EOF
        <add>
            <doc>
                <field name="id">$guid</field>
				<field name="owner_guid">{$entity->owner_guid}</field>
				<field name="container_guid">{$entity->container_guid}</field>
                <field name="name">$name</field>
                <field name="username">$username</field>
				<field name="description">$description</field>
                <field name="type">user</field>
				<field name="subtype">{$entity->getSubtype()}</field>
				<field name="access_id">{$entity->access_id}</field>
				<field name="time_created">{$entity->time_created}</field>
EOF;
				
	$valid_tag_names = elgg_get_registered_tag_metadata_names();
	
	if ($valid_tag_names && is_array($valid_tag_names)) {
		foreach ($valid_tag_names as $tagname) {
			$tags = $entity->$tagname;
			if ($tags && !is_array($tags)) {
				$tags = array($tags);
			}
			if ($tags && is_array($tags)) {
				foreach ($tags as $tag) {
					$t = elgg_solr_xml_format($tagname . '%%' . $tag);
						$doc .= <<<EOF
			<field name="tags">{$t}</field>
EOF;
				}
			}
		}
	}


$doc .= <<<EOF
            </doc>
        </add>
EOF;

	elgg_solr_push_doc($doc);
}


function elgg_solr_debug_log($message) {
	error_log($message);
}


function elgg_solr_push_doc($doc) {
	$debug = false;
	if (elgg_get_config('elgg_solr_debug')) {
		$debug = true;
	}
	
	$options = elgg_solr_get_adapter_options();
	if ($options['core']) {
		$options['core'] .= '/';
	}
	
	// Solr URL
    $url = "http://{$options['host']}:{$options['port']}{$options['path']}{$options['core']}update";
	
	if (!elgg_get_config('elgg_solr_nocommit')) {
		$url .= '?commit=true';
	}

    // Initialize cURL
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml")); 
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $doc);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_VERBOSE, 1);

    // Execute the request
    try {
		if ($debug) {
			elgg_solr_debug_log('attempting solr update with url: ' . $url);
			elgg_solr_debug_log('doc = ' . print_r($doc,1));
		}
        $response = curl_exec($ch);
		
		if ($debug) {
			elgg_solr_debug_log('curl response:  ' . print_r($response,1));
		}
    } catch( Exception $e) {
		if ($debug) {
			elgg_solr_debug_log('elgg_solr_add_update_object() - ' . $e->getMessage());
		}
    }

    curl_close($ch);
}


function elgg_solr_xml_format($text) {
	return htmlspecialchars(elgg_strip_tags($text), ENT_QUOTES, 'UTF-8');
}


function elgg_solr_get_access_query() {
	
	if (elgg_is_admin_logged_in() || elgg_get_ignore_access()) {
		return false; // no access limit
	}
	
	static $return;
	
	if ($return) {
		return $return;
	}
	
	$access = get_access_array();
	
	// access filter query
	if ($access) {
		$access_list = implode(" OR ", $access);
	}
	
	if (elgg_is_logged_in()) {

		// get friends
		// @TODO - is there a better way? Not sure if there's a limit on solr if
		// someone has a whole lot of friends...
		$friends = elgg_get_entities(array(
			'type' => 'user',
			'relationship' => 'friend',
			'relationship_guid' => elgg_get_logged_in_user_guid(),
			'inverse_relationship' => true,
			'limit' => false,
			'callback' => false // keep the query fast
		));
		
		$friend_guids = array();
		foreach ($friends as $friend) {
			$friend_guids[] = $friend->guid;
		}
			
		$friends_list = '';
		if ($friend_guids) {
			$friends_list = elgg_solr_escape_special_chars(implode(' OR ', $friend_guids));
		}
	}

	//$query->createFilterQuery('access')->setQuery("access_id:({$access_list}) OR (access_id:" . ACCESS_FRIENDS . " AND owner_guid:({$friends}))");
	$return = '';
	
	if ($access_list) {
		$return .= "access_id:(" . elgg_solr_escape_special_chars($access_list) . ")";
	}
	
	$fr_prefix = '';
	$fr_suffix = '';
	if ($return && $friends_list) {
		$return .= ' OR ';
		$fr_prefix = '(';
		$fr_suffix = ')';
	}
	
	if ($friends_list) {
		$return .= $fr_prefix . 'access_id:' . elgg_solr_escape_special_chars(ACCESS_FRIENDS) . ' AND owner_guid:(' . $friends_list . ')' . $fr_suffix;
	}

	return $return;
}



function elgg_solr_add_update_group_default($entity) {
	if (!is_registered_entity_type($entity->type, $entity->getSubtype())) {
		return false;
	}
	
	$name       = elgg_solr_xml_format($entity->name);
    $description = elgg_solr_xml_format($entity->description);

    // Build the user document to be posted
    $doc = <<<EOF
        <add>
            <doc>
				<field name="id">{$entity->guid}</field>
				<field name="title">{$name}</field>
				<field name="name">{$name}</field>
				<field name="description">{$description}</field>
                <field name="type">group</field>
				<field name="subtype">{$entity->getSubtype()}</field>
				<field name="access_id">{$entity->access_id}</field>
				<field name="container_guid">{$entity->container_guid}</field>
				<field name="owner_guid">{$entity->owner_guid}</field>
				<field name="time_created">{$entity->time_created}</field>
EOF;

	$valid_tag_names = elgg_get_registered_tag_metadata_names();
	
	if ($valid_tag_names && is_array($valid_tag_names)) {
		foreach ($valid_tag_names as $tagname) {
			$tags = $entity->$tagname;
			if ($tags && !is_array($tags)) {
				$tags = array($tags);
			}
			if ($tags && is_array($tags)) {
				foreach ($tags as $tag) {
					$t = elgg_solr_xml_format($tagname . '%%' . $tag);
						$doc .= <<<EOF
			<field name="tags">{$t}</field>
EOF;
				}
			}
		}
	}

$doc .= <<<EOF
            </doc>
        </add>
EOF;

	elgg_solr_push_doc($doc);
}


function elgg_solr_escape_special_chars($string) {
	// Lucene characters that need escaping with \ are + - && || ! ( ) { } [ ] ^ " ~ * ? : \
	$luceneReservedCharacters = preg_quote('+-&|!(){}[]^"~*?:\\');
	$query = preg_replace_callback('/([' . $luceneReservedCharacters . '])/',
		function($matches) {
			return '\\' . $matches[0];
		},
    $string);
	return $query;
}