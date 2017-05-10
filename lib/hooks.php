<?php

use Solarium\QueryType\Update\Query\Document\DocumentInterface;

/**
 * Configure file search
 *
 * @param string $hook   "search"
 * @param string $type   "object:file"
 * @param array  $value  Search results
 * @param array  $params Search params
 * @return array
 */
function elgg_solr_file_search($hook, $type, $value, $params) {

	$select = array(
		'start' => $params['offset'],
		'rows' => $params['limit'] ? $params['limit'] : 10,
		'fields' => array('id', 'title', 'description', 'score'),
	);

	if ($params['select'] && is_array($params['select'])) {
		$select = array_merge($select, $params['select']);
	}

	// create a client instance
	$client = elgg_solr_get_client();

	// get an update query instance
	$query = $client->createSelect($select);

	$default_sorts = array(
		'score' => 'desc',
		'time_created' => 'desc'
	);

	$sorts = $params['sorts'] ? $params['sorts'] : $default_sorts;
	$query->addSorts($sorts);

	$title_boost = elgg_solr_get_title_boost();
	$description_boost = elgg_solr_get_description_boost();

	// get the dismax component and set a boost query
	$dismax = $query->getEDisMax();
	$qf = "title^{$title_boost} description^{$description_boost} attr_content^{$description_boost}";
	if ($params['qf']) {
		$qf = $params['qf'];
	}
	// allow plugins to change default query fields
	$qf = elgg_trigger_plugin_hook('solr:query_fields', 'object', $params, $qf);
	$dismax->setQueryFields($qf);
	$dismax->setQueryAlternative('*:*');

	$boostQuery = elgg_solr_get_boost_query();
	if ($boostQuery) {
		$dismax->setBoostQuery($boostQuery);
	}

	// this query is now a dismax query
	$query->setQuery($params['query']);

	$params['fq']['type'] = 'type:object';
	$params['fq']['subtype'] = 'subtype:file';

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	} else {
		$filter_queries = $default_fq;
	}
	
	$filter_queries = elgg_trigger_plugin_hook('solr:filter_queries', 'object', $params, $filter_queries);

	if (!empty($filter_queries)) {
		foreach ($filter_queries as $key => $value) {
			$query->createFilterQuery($key)->setQuery($value);
		}
	}

	// get highlighting component and apply settings
	$hl = $query->getHighlighting();
	$hlfields = array('title', 'attr_content', 'description');
	if ($params['hlfields']) {
		$hlfields = $params['hlfields'];
	}
	$hl->setFields($hlfields);
	
	$hl_prefix = elgg_solr_get_hl_prefix();
	$hl_suffix = elgg_solr_get_hl_suffix();
	$hl->setSimplePrefix($hl_prefix);
	$hl->setSimplePostfix($hl_suffix);

	$fragsize = elgg_solr_get_fragsize();
	if (isset($params['fragsize'])) {
		$fragsize = (int) $params['fragsize'];
	}
	$hl->setFragSize($fragsize);

	// this executes the query and returns the result
	try {
		$resultset = $client->select($query);
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Get the highlighted snippet
	try {
		$highlighting = $resultset->getHighlighting();
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Count the total number of documents found by solr
	$count = $resultset->getNumFound();

	$search_results = array();

	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);

	foreach ($resultset as $document) {
		$search_results[$document->id] = array();
		$snippet = '';

		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
		$highlightedDoc = $highlighting->getResult($document->id);

		if ($highlightedDoc) {
			foreach ($highlightedDoc as $field => $highlight) {
				$snippet = implode(' (...) ', $highlight);

				$search_results[$document->id][$field] = $purifier->purify($snippet);
			}
		}
		$search_results[$document->id]['score'] = $document->score;

		// normalize description with attr_content
		$search_results[$document->id]['description'] = trim($search_results[$document->id]['description'] . ' ' . $search_results[$document->id]['attr_content']);
	}

	// get the entities in a single query
	// resort them into the order returned by solr by looping through $search_results
	$entities = array();
	$entities_unsorted = array();
	if ($search_results) {
		$entities_unsorted = elgg_get_entities(array(
			'guids' => array_keys($search_results),
			'limit' => false
		));
	}

	$show_score = elgg_get_plugin_setting('show_score', 'elgg_solr');
	foreach ($search_results as $guid => $matches) {
		foreach ($entities_unsorted as $e) {
			if ($e->guid == $guid) {

				$desc_suffix = '';
				if ($show_score == 'yes' && elgg_is_admin_logged_in()) {
					$desc_suffix .= elgg_view('output/longtext', array(
						'value' => elgg_echo('elgg_solr:relevancy', array($matches['score'])),
						'class' => 'elgg-subtext'
					));
				}


				if ($matches['title']) {
					$e->setVolatileData('search_matched_title', $matches['title']);
				} else {
					$e->setVolatileData('search_matched_title', $e->title);
				}

				if ($matches['description']) {
					$desc = $matches['description'];
				} else {
					$desc = elgg_get_excerpt($e->description, 100);
				}

				unset($matches['title']);
				unset($matches['description']);
				unset($matches['score']);
				$desc .= implode('...', $matches);

				$e->setVolatileData('search_matched_description', $desc . $desc_suffix);

				$entities[] = $e;
			}
		}
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

function elgg_solr_object_search($hook, $type, $return, $params) {

	$select = array(
		'start' => $params['offset'],
		'rows' => $params['limit'] ? $params['limit'] : 10,
		'fields' => array('id', 'title', 'description', 'score')
	);

	if ($params['select'] && is_array($params['select'])) {
		$select = array_merge($select, $params['select']);
	}

	// create a client instance
	$client = elgg_solr_get_client($select);

	// get an update query instance
	$query = $client->createSelect($select);

	$title_boost = elgg_solr_get_title_boost();
	$description_boost = elgg_solr_get_description_boost();

	// get the dismax component and set a boost query
	$dismax = $query->getEDisMax();
	$qf = "title^{$title_boost} description^{$description_boost}";
	if ($params['qf']) {
		$qf = $params['qf'];
	}
	// allow plugins to change default query fields
	$qf = elgg_trigger_plugin_hook('solr:query_fields', 'object', $params, $qf);
	$dismax->setQueryFields($qf);
	$dismax->setQueryAlternative('*:*');

	$boostQuery = elgg_solr_get_boost_query();
	if ($boostQuery) {
		$dismax->setBoostQuery($boostQuery);
	}

	// this query is now a dismax query
	$query->setQuery($params['query']);

	$default_sorts = array(
		'score' => 'desc',
		'time_created' => 'desc'
	);

	$sorts = $params['sorts'] ? $params['sorts'] : $default_sorts;
	$query->addSorts($sorts);

	// make sure we're only getting objectss
	$params['fq']['type'] = 'type:object';

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	} else {
		$filter_queries = $default_fq;
	}

	$filter_queries = elgg_trigger_plugin_hook('solr:filter_queries', 'object', $params, $filter_queries);
	
	if (!empty($filter_queries)) {
		foreach ($filter_queries as $key => $value) {
			$query->createFilterQuery($key)->setQuery($value);
		}
	}

	// get highlighting component and apply settings
	$hl = $query->getHighlighting();
	$hlfields = array('title', 'description');
	if ($params['hlfields']) {
		$hlfields = $params['hlfields'];
	}
	$hl->setFields($hlfields);
	
	$hl_prefix = elgg_solr_get_hl_prefix();
	$hl_suffix = elgg_solr_get_hl_suffix();
	$hl->setSimplePrefix($hl_prefix);
	$hl->setSimplePostfix($hl_suffix);

	$fragsize = elgg_solr_get_fragsize();
	if (isset($params['fragsize'])) {
		$fragsize = (int) $params['fragsize'];
	}
	$hl->setFragSize($fragsize);

	// this executes the query and returns the result
	try {
		$resultset = $client->select($query);
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Get the highlighted snippet
	try {
		$highlighting = $resultset->getHighlighting();
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Count the total number of documents found by solr
	$count = $resultset->getNumFound();

	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);

	$search_results = array();
	foreach ($resultset as $document) {
		$search_results[$document->id] = array();
		$snippet = '';

		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
		$highlightedDoc = $highlighting->getResult($document->id);

		if ($highlightedDoc) {
			foreach ($highlightedDoc as $field => $highlight) {
				$snippet = implode(' (...) ', $highlight);

				$search_results[$document->id][$field] = $purifier->purify($snippet);
			}
		}

		$search_results[$document->id]['score'] = $document->score;
	}

	// get the entities
	$entities = array();
	$entities_unsorted = array();
	if ($search_results) {
		$entities_unsorted = elgg_get_entities(array(
			'guids' => array_keys($search_results),
			'limit' => false
		));
	}

	$show_score = elgg_get_plugin_setting('show_score', 'elgg_solr');
	foreach ($search_results as $guid => $matches) {
		foreach ($entities_unsorted as $e) {

			$desc_suffix = '';
			if ($show_score == 'yes' && elgg_is_admin_logged_in()) {
				$desc_suffix .= elgg_view('output/longtext', array(
					'value' => elgg_echo('elgg_solr:relevancy', array($matches['score'])),
					'class' => 'elgg-subtext'
				));
			}

			if ($e->guid == $guid) {
				if ($matches['title']) {
					$e->setVolatileData('search_matched_title', $matches['title']);
				} else {
					$e->setVolatileData('search_matched_title', $e->title);
				}

				if ($matches['description']) {
					$desc = $matches['description'];
				} else {
					$desc = elgg_get_excerpt($e->description, 100);
				}

				unset($matches['title']);
				unset($matches['description']);
				unset($matches['score']);
				$desc .= implode('...', $matches);

				$e->setVolatileData('search_matched_description', $desc . $desc_suffix);
				$entities[] = $e;
			}
		}
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

function elgg_solr_user_search($hook, $type, $return, $params) {

	$select = array(
		'start' => $params['offset'],
		'rows' => $params['limit'] ? $params['limit'] : 10,
		'fields' => array('id', 'name', 'username', 'description', 'score')
	);

	if ($params['select'] && is_array($params['select'])) {
		$select = array_merge($select, $params['select']);
	}

	// create a client instance
	$client = elgg_solr_get_client();

	// get an update query instance
	$query = $client->createSelect($select);

	$default_sorts = array(
		'score' => 'desc',
		'time_created' => 'desc'
	);

	$sorts = $params['sorts'] ? $params['sorts'] : $default_sorts;
	$query->addSorts($sorts);

	$title_boost = elgg_solr_get_title_boost();
	$description_boost = elgg_solr_get_description_boost();

	// get the dismax component and set a boost query
	$dismax = $query->getEDisMax();
	$qf = "name^{$title_boost} username^{$title_boost} description^{$description_boost}";
	if ($params['qf']) {
		$qf = $params['qf'];
	}
	// allow plugins to change default query fields
	$qf = elgg_trigger_plugin_hook('solr:query_fields', 'user', $params, $qf);
	$dismax->setQueryFields($qf);
	$dismax->setQueryAlternative('*:*');

	// no time boost for users
	/*
	  $boostQuery = elgg_solr_get_boost_query();
	  if ($boostQuery) {
	  $dismax->setBoostQuery($boostQuery);
	  }
	 * 
	 */

	// this query is now a dismax query
	$query->setQuery($params['query']);

	// make sure we're only getting users
	$params['fq']['type'] = 'type:user';

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	} else {
		$filter_queries = $default_fq;
	}
	
	$filter_queries = elgg_trigger_plugin_hook('solr:filter_queries', 'user', $params, $filter_queries);

	if (!empty($filter_queries)) {
		foreach ($filter_queries as $key => $value) {
			$query->createFilterQuery($key)->setQuery($value);
		}
	}

	// get highlighting component and apply settings
	$hl = $query->getHighlighting();
	$hlfields = array('name', 'username', 'description');
	if ($params['hlfields']) {
		$hlfields = $params['hlfields'];
	}
	$hl->setFields($hlfields);
	
	$hl_prefix = elgg_solr_get_hl_prefix();
	$hl_suffix = elgg_solr_get_hl_suffix();
	$hl->setSimplePrefix($hl_prefix);
	$hl->setSimplePostfix($hl_suffix);

	$fragsize = elgg_solr_get_fragsize();
	if (isset($params['fragsize'])) {
		$fragsize = (int) $params['fragsize'];
	}
	$hl->setFragSize($fragsize);

	// this executes the query and returns the result
	try {
		$resultset = $client->select($query);
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Get the highlighted snippet
	try {
		$highlighting = $resultset->getHighlighting();
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Count the total number of documents found by solr
	$count = $resultset->getNumFound();

	$search_results = array();

	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);

	foreach ($resultset as $document) {
		$search_results[$document->id] = array();
		$snippet = '';

		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
		$highlightedDoc = $highlighting->getResult($document->id);

		if ($highlightedDoc) {
			foreach ($highlightedDoc as $field => $highlight) {
				$snippet = implode(' (...) ', $highlight);

				$search_results[$document->id][$field] = $purifier->purify($snippet);
			}
		}
		$search_results[$document->id]['score'] = $document->score;
	}

	// get the entities
	$entities = array();
	$entities_unsorted = array();
	if ($search_results) {
		$entities_unsorted = elgg_get_entities(array(
			'guids' => array_keys($search_results),
			'limit' => false
		));
	}

	$show_score = elgg_get_plugin_setting('show_score', 'elgg_solr');
	foreach ($search_results as $guid => $matches) {
		foreach ($entities_unsorted as $e) {
			if ($e->guid == $guid) {

				$desc_suffix = '';
				if ($show_score == 'yes' && elgg_is_admin_logged_in()) {
					$desc_suffix .= elgg_view('output/longtext', array(
						'value' => elgg_echo('elgg_solr:relevancy', array($matches['score'])),
						'class' => 'elgg-subtext'
					));
				}

				if ($matches['name']) {
					$name = $matches['name'];
					if ($matches['username']) {
						$name .= ' (@' . $matches['username'] . ')';
					} else {
						$name .= ' (@' . $e->username . ')';
					}
					$e->setVolatileData('search_matched_name', $name);
					$e->setVolatileData('search_matched_title', $name);
				} else {
					$name = $e->name;
					if ($matches['username']) {
						$name .= ' (@' . $matches['username'] . ')';
					} else {
						$name .= ' (@' . $e->username . ')';
					}
					$e->setVolatileData('search_matched_name', $name);
					$e->setVolatileData('search_matched_title', $name);
				}

				// anything not already matched can be lumped in with the description
				unset($matches['name']);
				unset($matches['username']);
				unset($matches['score']);
				$desc_suffix .= implode('...', $matches);

				$desc_hl = search_get_highlighted_relevant_substrings($e->description, $params['query']);
				$e->setVolatileData('search_matched_description', $desc_hl . $desc_suffix);
				$entities[] = $e;
			}
		}
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

function elgg_solr_group_search($hook, $type, $return, $params) {

	$select = array(
		'start' => $params['offset'],
		'rows' => isset($params['limit']) ? $params['limit'] : 10,
		'fields' => array('id', 'name', 'description', 'score')
	);

	if (isset($params['select']) && is_array($params['select'])) {
		$select = array_merge($select, $params['select']);
	}

	// create a client instance
	$client = elgg_solr_get_client();

	// get an update query instance
	$query = $client->createSelect($select);

	$default_sorts = array(
		'score' => 'desc',
		'time_created' => 'desc'
	);

	$sorts = isset($params['sorts']) ? $params['sorts'] : $default_sorts;
	$query->addSorts($sorts);

	$title_boost = elgg_solr_get_title_boost();
	$description_boost = elgg_solr_get_description_boost();

	// get the dismax component and set a boost query
	$dismax = $query->getEDisMax();
	$qf = "name^{$title_boost} description^{$description_boost}";
	if (isset($params['qf'])) {
		$qf = $params['qf'];
	}
	// allow plugins to change default query fields
	$qf = elgg_trigger_plugin_hook('solr:query_fields', 'group', $params, $qf);
	$dismax->setQueryFields($qf);
	$dismax->setQueryAlternative('*:*');

	$boostQuery = elgg_solr_get_boost_query();
	if ($boostQuery) {
		$dismax->setBoostQuery($boostQuery);
	}

	// this query is now a dismax query
	$query->setQuery($params['query']);

	// make sure we're only getting groups
	$params['fq']['type'] = 'type:group';

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	} else {
		$filter_queries = $default_fq;
	}
	
	$filter_queries = elgg_trigger_plugin_hook('solr:filter_queries', 'group', $params, $filter_queries);

	if (!empty($filter_queries)) {
		foreach ($filter_queries as $key => $value) {
			$query->createFilterQuery($key)->setQuery($value);
		}
	}

	// get highlighting component and apply settings
	$hl = $query->getHighlighting();
	$hlfields = array('name', 'description');
	if (isset($params['hlfields'])) {
		$hlfields = $params['hlfields'];
	}
	$hl->setFields($hlfields);
	
	$hl_prefix = elgg_solr_get_hl_prefix();
	$hl_suffix = elgg_solr_get_hl_suffix();
	$hl->setSimplePrefix($hl_prefix);
	$hl->setSimplePostfix($hl_suffix);

	$fragsize = elgg_solr_get_fragsize();
	if (isset($params['fragsize'])) {
		$fragsize = (int) $params['fragsize'];
	}
	$hl->setFragSize($fragsize);


	// this executes the query and returns the result
	try {
		$resultset = $client->select($query);
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Get the highlighted snippet
	try {
		$highlighting = $resultset->getHighlighting();
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Count the total number of documents found by solr
	$count = $resultset->getNumFound();

	$search_results = array();

	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);

	foreach ($resultset as $document) {
		$search_results[$document->id] = array();
		$snippet = '';

		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
		$highlightedDoc = $highlighting->getResult($document->id);

		if ($highlightedDoc) {
			foreach ($highlightedDoc as $field => $highlight) {
				$snippet = implode(' (...) ', $highlight);

				$search_results[$document->id][$field] = $purifier->purify($snippet);
			}
		}

		$search_results[$document->id]['score'] = $document->score;
	}

	// get the entities
	$entities = array();
	$entities_unsorted = array();
	if ($search_results) {
		$entities_unsorted = elgg_get_entities(array(
			'guids' => array_keys($search_results),
			'limit' => false
		));
	}

	$show_score = elgg_get_plugin_setting('show_score', 'elgg_solr');
	foreach ($search_results as $guid => $matches) {
		foreach ($entities_unsorted as $e) {

			$desc_suffix = '';
			if ($show_score == 'yes' && elgg_is_admin_logged_in()) {
				$desc_suffix .= elgg_view('output/longtext', array(
					'value' => elgg_echo('elgg_solr:relevancy', array($matches['score'])),
					'class' => 'elgg-subtext'
				));
			}

			if ($e->guid == $guid) {
				if ($matches['name']) {
					$name = $matches['name'];
					$e->setVolatileData('search_matched_name', $name);
					$e->setVolatileData('search_matched_title', $name);
				} else {
					$name = $e->name;
					$e->setVolatileData('search_matched_name', $name);
					$e->setVolatileData('search_matched_title', $name);
				}

				if (isset($matches['description'])) {
					$desc = $matches['description'];
				} else {
					$desc = search_get_highlighted_relevant_substrings($e->description, $params['query']);
				}


				unset($matches['name']);
				unset($matches['description']);
				unset($matches['score']);
				$desc .= implode('...', $matches);

				$e->setVolatileData('search_matched_description', $desc . $desc_suffix);

				$entities[] = $e;
			}
		}
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

function elgg_solr_user_settings_save($hook, $type, $return, $params) {
	$user_guid = (int) get_input('guid');
	$user = get_user($user_guid);

	if (!$user) {
		return $return;
	}

	$guids = elgg_get_config('elgg_solr_sync');
	if (!is_array($guids)) {
		$guids = array();
	}
	$guids[$user->guid] = 1; // use key to keep it unique

	elgg_set_config('elgg_solr_sync', $guids);

	return $return;
}

function elgg_solr_tag_search($hook, $type, $return, $params) {

	$valid_tag_names = elgg_get_registered_tag_metadata_names();

	if (!$valid_tag_names || !is_array($valid_tag_names)) {
		return array('entities' => array(), 'count' => 0);
	}

	// if passed a tag metadata name, only search on that tag name.
	// tag_name isn't included in the params because it's specific to
	// tag searches.
	if ($tag_names = get_input('tag_names')) {
		if (is_array($tag_names)) {
			$search_tag_names = $tag_names;
		} else {
			$search_tag_names = array($tag_names);
		}

		// check these are valid to avoid arbitrary metadata searches.
		foreach ($search_tag_names as $i => $tag_name) {
			if (!in_array($tag_name, $valid_tag_names)) {
				unset($search_tag_names[$i]);
			}
		}
	} else {
		$search_tag_names = $valid_tag_names;
	}

	$query_parts = array();
	foreach ($search_tag_names as $tagname) {
		// @note - these need to be treated as literal exact matches, so encapsulate in double-quotes
		$query_parts[] = 'tags:"' . elgg_solr_escape_special_chars($tagname . '%%' . $params['query']) . '"';
	}

	if (!$query_parts) {
		return array('entities' => array(), 'count' => 0);
	}

	$q = implode(' OR ', $query_parts);

	$select = array(
		'query' => $q,
		'start' => $params['offset'],
		'rows' => $params['limit'],
		'fields' => array('id', 'title', 'description', 'score')
	);

	if ($params['select'] && is_array($params['select'])) {
		$select = array_merge($select, $params['select']);
	}

	$client = elgg_solr_get_client();
// get an update query instance
	$query = $client->createSelect($select);

	$default_sorts = array(
		'score' => 'desc',
		'time_created' => 'desc'
	);

	$sorts = $params['sorts'] ? $params['sorts'] : $default_sorts;
	$query->addSorts($sorts);

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	} else {
		$filter_queries = $default_fq;
	}

	$filter_queries = elgg_trigger_plugin_hook('solr:filter_queries', 'tag', $params, $filter_queries);
	
	if (!empty($filter_queries)) {
		foreach ($filter_queries as $key => $value) {
			$query->createFilterQuery($key)->setQuery($value);
		}
	}

	// get highlighting component and apply settings
	$hl = $query->getHighlighting();
	$hl->setFields(array('tags'));
	
	$hl_prefix = elgg_solr_get_hl_prefix();
	$hl_suffix = elgg_solr_get_hl_suffix();
	$hl->setSimplePrefix($hl_prefix);
	$hl->setSimplePostfix($hl_suffix);

	// this executes the query and returns the result
	try {
		$resultset = $client->select($query);
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Get the highlighted snippet
	try {
		$highlighting = $resultset->getHighlighting();
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Count the total number of documents found by solr
	$count = $resultset->getNumFound();

	$search_results = array();

	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);

	foreach ($resultset as $document) {
		$search_results[$document->id] = array();
		$snippet = '';

		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
		$highlightedDoc = $highlighting->getResult($document->id);

		if ($highlightedDoc) {
			foreach ($highlightedDoc as $field => $highlight) {
				// a little hackery for matched tags
				$snippet = array();
				foreach ($highlight as $key => $h) {
					$matched = $hl_prefix;
					$matched .= substr(strstr(elgg_strip_tags($h), '%%'), 2);
					$matched .= $hl_suffix;
					$snippet[] = $matched;
				}

				$display = implode(', ', $snippet);
				$search_results[$document->id][$field] = $purifier->purify($display);
			}
		}
		$search_results[$document->id]['score'] = $document->score;
	}

	// get the entities
	$entities = array();
	$entities_unsorted = array();
	if ($search_results) {
		$entities_unsorted = elgg_get_entities(array(
			'guids' => array_keys($search_results),
			'limit' => false
		));
	}

	$show_score = elgg_get_plugin_setting('show_score', 'elgg_solr');
	foreach ($search_results as $guid => $matches) {
		foreach ($entities_unsorted as $e) {
			if ($e->guid == $guid) {

				$desc_suffix = '';
				if ($show_score == 'yes' && elgg_is_admin_logged_in()) {
					$desc_suffix .= elgg_view('output/longtext', array(
						'value' => elgg_echo('elgg_solr:relevancy', array($matches['score'])),
						'class' => 'elgg-subtext'
					));
				}

				$title = $e->title ? $e->title : $e->name;
				$description = $e->description;
				$e->setVolatileData('search_matched_title', $title);
				$e->setVolatileData('search_matched_description', elgg_get_excerpt($description) . $desc_suffix);

				$e->setVolatileData('search_matched_extra', $matches['tags']);
				$entities[] = $e;
			}
		}
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

// optimize our index daily
function elgg_solr_daily_cron($hook, $type, $return, $params) {
	$ia = elgg_set_ignore_access(true);

	$client = elgg_solr_get_client();
	$query = $client->createUpdate();
	$query->addOptimize(true, true, 5);

	try {
		$client->update($query);
	} catch (Exception $e) {
		elgg_solr_exception_log($e);
		// fail silently
	}


	// try to catch any missed deletions
	$options = array(
		'guid' => elgg_get_site_entity()->guid,
		'annotation_names' => array('elgg_solr_delete_cache'),
		'limit' => false
	);

	$annotations = new ElggBatch('elgg_get_annotations', $options, null, 25, false);
	foreach ($annotations as $a) {
		$client = elgg_solr_get_client();
		$query = $client->createUpdate();
		$query->addDeleteById($a->value);
		$query->addCommit();

		try {
			$client->update($query);
		} catch (Exception $e) {
			elgg_solr_exception_log($e);
			// well we tried...
		}

		$a->delete();
	}

	elgg_set_ignore_access($ia);
}

/**
 * NOTE - this is only used in Elgg 1.8 as comments are annotations
 * 
 * @param type $hook
 * @param type $type
 * @param type $return
 * @param type $params
 * @return null
 */
function elgg_solr_comment_search($hook, $type, $return, $params) {

	$entities = array();

	$select = array(
		'start' => $params['offset'],
		'rows' => $params['limit'] ? $params['limit'] : 10,
		'fields' => array('id', 'container_guid', 'description', 'owner_guid', 'time_created', 'score'),
	);

	if ($params['select'] && is_array($params['select'])) {
		$select = array_merge($select, $params['select']);
	}

	// create a client instance
	$client = elgg_solr_get_client();

	// get an update query instance
	$query = $client->createSelect($select);

	$default_sort = array(
		'score' => 'desc',
		'time_created' => 'desc'
	);
	$sorts = $params['sorts'] ? $params['sorts'] : $default_sort;

	$query->addSorts($sorts);

	$description_boost = elgg_solr_get_description_boost();

	// get the dismax component and set a boost query
	$dismax = $query->getEDisMax();
	$qf = "description^{$description_boost}";
	if ($params['qf']) {
		$qf = $params['qf'];
	}
	// allow plugins to change default query fields
	$qf = elgg_trigger_plugin_hook('solr:query_fields', 'comment', $params, $qf);
	$dismax->setQueryFields($qf);

	$boostQuery = elgg_solr_get_boost_query();
	if ($boostQuery) {
		$dismax->setBoostQuery($boostQuery);
	}

	// this query is now a dismax query
	$query->setQuery($params['query']);


	// make sure we're only getting comments
	$params['fq']['type'] = 'type:annotation';
	$params['fq']['subtype'] = 'subtype:generic_comment';

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	} else {
		$filter_queries = $default_fq;
	}

	if (!empty($filter_queries)) {
		foreach ($filter_queries as $key => $value) {
			$query->createFilterQuery($key)->setQuery($value);
		}
	}

	// get highlighting component and apply settings
	$hl = $query->getHighlighting();
	$hl->setFields(array('description'));
	
	$hl_prefix = elgg_solr_get_hl_prefix();
	$hl_suffix = elgg_solr_get_hl_suffix();
	$hl->setSimplePrefix($hl_prefix);
	$hl->setSimplePostfix($hl_suffix);

	$fragsize = elgg_solr_get_fragsize();
	if (isset($params['fragsize'])) {
		$fragsize = (int) $params['fragsize'];
	}
	$hl->setFragSize($fragsize);


	// this executes the query and returns the result
	try {
		$resultset = $client->select($query);
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Get the highlighted snippet
	try {
		$highlighting = $resultset->getHighlighting();
	} catch (Exception $e) {
		register_error(elgg_echo('elgg_solr:search:error'));
		elgg_solr_debug_log($e->getMessage());
		elgg_solr_exception_log($e);
		return null;
	}

	// Count the total number of documents found by solr
	$count = $resultset->getNumFound();

	$show_score = elgg_get_plugin_setting('show_score', 'elgg_solr');

	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);

	foreach ($resultset as $document) {
		// comments entity_guid stored as container_guid in solr
		$entity = get_entity($document->container_guid);

		if (!$entity) {
			$entity = new ElggObject();
			$entity->setVolatileData('search_unavailable_entity', TRUE);
		}

		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
		$highlightedDoc = $highlighting->getResult($document->id);

		if ($highlightedDoc) {
			foreach ($highlightedDoc as $highlight) {
				$snippet = implode(' (...) ', $highlight);

				$snippet = $purifier->purify($snippet);
			}
		}

		if (!$snippet) {
			$snippet = search_get_highlighted_relevant_substrings(elgg_get_excerpt($document->description), $params['query']);
		}

		if ($show_score == 'yes' && elgg_is_admin_logged_in()) {
			$snippet .= elgg_view('output/longtext', array(
				'value' => elgg_echo('elgg_solr:relevancy', array($document->score)),
				'class' => 'elgg-subtext'
			));
		}

		$comments_data = $entity->getVolatileData('search_comments_data');
		if (!$comments_data) {
			$comments_data = array();
		}
		$comments_data[] = array(
			'annotation_id' => substr(strstr(elgg_strip_tags($document->id), ':'), 1),
			'text' => $snippet,
			'owner_guid' => $document->owner_guid,
			'time_created' => $document->time_created,
		);
		$entity->setVolatileData('search_comments_data', $comments_data);

		$entities[] = $entity;
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

/**
 * Add user profile fields, group membership, and user friendships to the Solr index
 * 
 * @param string            $hook   "elgg_solr:index"
 * @param string            $type   "user"
 * @param DocumentInterface $return Solr document
 * @param array             $params Hook params
 * @return DocumentInterface
 */
function elgg_solr_index_user($hook, $type, $return, $params) {

	$entity = elgg_extract('entity', $params);
	if (!$entity instanceof ElggUser) {
		return;
	}
	
	// Add username to doc
	$return->username = $entity->username;

	// Add profile fields to additional fields	
	$profile_fields = elgg_get_config('profile_fields');
	if (is_array($profile_fields) && sizeof($profile_fields) > 0) {
		foreach ($profile_fields as $shortname => $valtype) {

			if (is_array($entity->$shortname) || $valtype == 'tags') {
				$key = 'profile_' . $shortname . '_ss';
			} else {
				$key = 'profile_' . $shortname . '_s';
			}

			$return->$key = $entity->$shortname;
		}
	}

	// Index group membership
	$group_guids = [];
	$groups_batch = new ElggBatch('elgg_get_entities_from_relationship', [
		'type' => 'group',
		'relationship' => 'member',
		'relationship_guid' => $entity->guid,
		'limit' => 0,
		'callback' => false,
	]);
	foreach ($groups_batch as $group) {
		$group_guids[] = $group->guid;
	}

	$return->groups_is = $group_guids;
	$return->groups_count_i = count($group_guids);

	// Index friendships (people friended by this user)
	$friends_guids = [];
	$friends_batch = new ElggBatch('elgg_get_entities_from_relationship', [
		'type' => 'user',
		'relationship' => 'friend',
		'relationship_guid' => $entity->guid,
		'limit' => 0,
		'callback' => false,
	]);
	foreach ($friends_batch as $friend) {
		$friends_guids[] = $friend->guid;
	}

	$return->friends_is = $friends_guids;
	$return->friends_count_i = count($friends_guids);

	// Index friendships (people that friended this user)
	$friends_of_guids = [];
	$friends_of_batch = new ElggBatch('elgg_get_entities_from_relationship', [
		'type' => 'user',
		'relationship' => 'friend',
		'relationship_guid' => $entity->guid,
		'inverse_relationship' => true,
		'limit' => 0,
		'callback' => false,
	]);
	foreach ($friends_of_batch as $friend_of) {
		$friends_of_guids[] = $friend_of->guid;
	}

	$return->friends_of_is = $friends_of_guids;
	$return->friends_of_count_i = count($friends_of_guids);

	$return->last_login_i = (int) $entity->last_login;
	$return->last_action_i = (int) $entity->last_action;
	$return->has_pic_b = (bool) $entity->icontime;

	$return->access_list_is = get_access_array($entity->guid, 0, true);
	
	return $return;
}

/**
 * Index group profile fields, members list
 *
 * @param string            $hook   "elgg_solr:index"
 * @param string            $type   "group"
 * @param DocumentInterface $return Solr document
 * @param array             $params Hook params
 * @return DocumentInterface
 */
function elgg_solr_index_group($hook, $type, $return, $params) {
	
	$entity = elgg_extract('entity', $params);
	if (!$entity instanceof ElggGroup) {
		return;
	}

	// Add group fields to additional fields
	$group_fields = elgg_get_config('group');
	if (is_array($group_fields) && sizeof($group_fields) > 0) {
		foreach ($group_fields as $shortname => $valtype) {
			if (is_array($entity->$shortname)) {
				$key = 'group_' . $shortname . '_ss';
			} else {
				$key = 'group_' . $shortname . '_s';
			}

			$return->$key = $entity->$shortname;
		}
	}

	// Add all members to the doc
	$members = [];
	$members_batch = new ElggBatch('elgg_get_entities_from_relationship', [
		'types' => 'user',
		'relationship' => 'member',
		'relationship_guid' => $entity->guid,
		'inverse_relationship' => true,
		'limit' => 0,
		'callback' => false,
	]);
	foreach ($members_batch as $member) {
		$members[] = $member->guid;
	}

	$return->members_is = $members;
	$return->members_count_i = count($members);

	return $return;
}

/**
 * Add likes to indexable annotations
 *
 * @param string   $hook   "elgg_solr:can_index"
 * @param string   $type   "annotation"
 * @param string[] $return An array of annotation names
 * @param array    $params Hook params
 * @return string[]
 */
function elgg_solr_annotation_can_index($hook, $type, $return, $params) {
	return array_merge($return, ['likes']);
}

/**
 * Update access collection hook
 *
 * @param string $hook   'access:collections:add_user'|'access:collections:remove_user'
 * @param string $type   'collection'
 * @param mixed  $return Hook result
 * @param array  $params Hook params
 * @return void
 */
function elgg_solr_collection_update($hook, $type, $return, $params) {
	$user_guid = elgg_extract('user_guid', $params);
	elgg_solr_defer_index_update($user_guid);
}

/**
 * Delete access collection hook
 *
 * @param string $hook   'access:collections:deletecollection'
 * @param string $type   'collection'
 * @param mixed  $return Hook result
 * @param array  $params Hook params
 * @return void
 */
function elgg_solr_collection_delete($hook, $type, $return, $params) {
	$collection_id = elgg_extract('collection_id', $params);
	$members = get_members_of_access_collection($collection_id, true);
	foreach ($members as $member_guid) {
		elgg_solr_defer_index_update($member_guid);
	}
}