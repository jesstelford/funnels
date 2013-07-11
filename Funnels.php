<?php
/**
 * Piwik - Open source web analytics
 * Funnel Plugin - Analyse and visualise goal funnels
 * 
 * @link http://mysociety.org
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 * @version 0.2
 * 
 * @category Piwik_Plugins
 * @package Piwik_Funnels
 */

/**
 *
 * @package Piwik_Funnels
 */
class Piwik_Funnels extends Piwik_Plugin
{
    
    // 'magic' number to indicate a conversion that was done manually
	const INDEX_MANUAL_CONVERSION = 0;
    const TIME_IN_PAST_TO_SEARCH_FOR_VISITOR = 86400; //24 hours
    /**
     * Return information about this plugin.
     *
     * @see Piwik_Plugin
     *
     * @return array
     */
    public function getInformation()
    {
        return array(
            'name' => 'Funnels',
            'description' => Piwik_Translate('Funnels_PluginDescription'),
            'author' => 'Smile',
            'author_homepage' => 'http://www.smile.fr/',
            'version' => '1.11.1',
            'translationAvailable' => true,
            'homepage' => 'https://github.com/YanK-fr/funnels',
            'TrackerPlugin' => true, // this plugin must be loaded during the stats logging
        );
    }
    
    function getListHooksRegistered()
    {
        // Note that we don't hook into the new visitor callback as it is not 
        // necessary - The only time it would be useful is when triggering a 
        // manual goal conversion for a new user, but in that case, it means 
        // that there is no referring Action, and the actual goal conversion 
        // is logged elsewhere in Piwik, so we don't need to do anything extra
        $hooks = array(
            'AssetManager.getCssFiles' => 'getCssFiles',
            'AssetManager.getJsFiles' => 'getJsFiles',
            'Menu.add' => 'addMenus',
            'Common.fetchWebsiteAttributes' => 'fetchFunnelsFromDb',
            'Tracker.Action.record' => 'recordFunnelSteps',
            'Tracker.knownVisitorUpdate' => 'recordManualFunnelSteps',
            'ArchiveProcessing_Day.compute' => 'archiveDay',
            'ArchiveProcessing_Period.compute' => 'archivePeriod',
            'Tracker.Visit.isExcluded' => 'includeManualURLGoal',
        );
        return $hooks;
    }
    
    function getJsFiles( $notification )
    {
        $jsFiles = &$notification->getNotificationObject();
        $jsFiles[] = "plugins/Funnels/templates/FunnelForm.js";
    }

    function getCssFiles( $notification )
    {
        $cssFiles = &$notification->getNotificationObject();
        $cssFiles[] = "plugins/Funnels/templates/funnels.css";
    }
    
    function fetchFunnelsFromDb($notification)
    {
        $idsite = $notification->getNotificationInfo();

        // add the 'funnel' entry in the website array
        $array =& $notification->getNotificationObject();
        $array['funnels'] = Piwik_Funnels_API::getInstance()->getFunnels($idsite);
    }
    
    /**
     * This is called within the function handleKnownVisit() in 
     * core/Tracker/Visit.php@315
     *
     * We are catching this callback purely to be able to log manual goal 
     * conversions since they don't trigger the Action record callback.
     *
     * Note that this is called BEFORE this action is logged to the DB, which 
     * means we can take advantage of the fact that the last 'exit' action ID 
     * is the 'referring' ID, and the one to be updated to (ie; passed in in 
     * the notification object) is the 'current' action ID
     * 
     * @param mixed $notification See the variable $valuesToUpdate in core/Tracker/Visit.php@318-361
     * @access public
     * @return void
     */
    function recordManualFunnelSteps( $notification )
    {
        $info = $notification->getNotificationObject();

        // if this is NOT a manual conversion, skip this and let the Action 
        // callback catch it
        if (isset($info['visit_exit_idaction_url']) && $info['visit_exit_idaction_url'] !== Piwik_Funnels::INDEX_MANUAL_CONVERSION) {
            return;
        }

        $idSite = Piwik_Common::getRequestVar("idsite");

        // Ok, so the 'current' idActionUrl is stored in $info['visit_exit_idaction_url']
        // This callback is called before the current action is actually 
        // stored in the DB, so we can just grab the most recent row from 
        // log_link_visit_action to get the refering action.
        // Also use this query to find the Visit ID of this visit

		$timeLookBack = date('Y-m-d H:i:s', time() - self::TIME_IN_PAST_TO_SEARCH_FOR_VISITOR);

        $visits = Piwik_Query(
            "SELECT idvisit, visit_exit_idaction_url
            FROM ".Piwik_Common::prefixTable('log_visit')." 
            WHERE idsite = ? 
            AND   visit_last_action_time >= ?
            ORDER BY visit_last_action_time DESC
            LIMIT 1",
            array($idSite, $timeLookBack)
        );

        $visit = $visits->fetch();

        // no visits, yet this is in the known visits callback. Something's 
        // gone wrong, so just fail silently
        if ($visit === false) {
            return;
        }

        $idActionUrl = $visit['visit_exit_idaction_url']; 
        $idVisit = $visit['idvisit']; 
		
        $this->doStepMatchAndSave(
            $idSite,
            $idVisit,
            $idActionUrl
        );

    }

    /**
     * This is called when an Action is called on piwik.php - ie, an actul 
     * visitor has hit the site, and the JS has performed a call to Piwik. 
     * This means that a manual goal conversion will NOT trigger this 
     * callback! 
     * 
     * @param mixed $notification 
     * @access public
     * @return void
     */
    function recordFunnelSteps( $notification )
    {
        $info = $notification->getNotificationInfo();
        $idSite = $info['idSite'];

        $action = $notification->getNotificationObject();

        // idRefererActionurl is the UID of theuer's LAST Action interacting 
        // with Piwik.
        // These two together give a link to a history of actions, and form a 
        // type of linked list in the log_funnel_step table.
        $this->doStepMatchAndSave(
            $idSite,
            $info['idVisit'],
            $info['idRefererActionUrl'],
            $action->getActionName(),
            htmlspecialchars_decode($action->getActionUrl()),
            $action->getIdActionUrl()
        );

    }

    function doStepMatchAndSave(
        $idSite,
        $idVisit,
        $idRefererAction,
        $actionName = "",
        $actionUrl = "",
        $idActionUrl = Piwik_Funnels::INDEX_MANUAL_CONVERSION
    ) {

        printDebug('Looking for funnel steps');
        $websiteAttributes = Piwik_Tracker_Cache::getCacheWebsiteAttributes( $idSite );
        if(isset($websiteAttributes['funnels']))
        {
            $funnels = $websiteAttributes['funnels'];
            printDebug('got funnel steps');
        } else {
            $funnels = array();
        }

        if (count($funnels) <= 0)
        {
            return;
        }

        printDebug("idActionUrl " . $idActionUrl . " idSite " . $idSite . " idVisit " . $idVisit . " idRefererAction " . $idRefererAction);
        // Is this the next action for a recorded funnel step? 
        $previous_step_action = Piwik_Query("UPDATE ".Piwik_Common::prefixTable('log_funnel_step')."
            SET   idaction_url_next = ?
            WHERE idsite = ? 
            AND   idvisit = ? 
            AND   idaction_url = ?
            AND   idaction_url_next is null", 
            array($idActionUrl, $idSite, $idVisit, $idRefererAction));

        // early out for special case of manual conversion
        // Since this is a manual conversion for a goal, there is no URL to 
        // match with, so the following loop is simply a waste of resources
        if ($idActionUrl == Piwik_Funnels::INDEX_MANUAL_CONVERSION) {
            return;
        }

        foreach($funnels as &$funnel)
        {
            $steps = $funnel['steps'];

            foreach($steps as &$step) 
            {               
                $url = $actionUrl;

                // Matching on Page Title
                if($step['match_attribute'] == 'title')
                {
                    $url = $actionName;
                }

                if (self::isMatch($url, $step['pattern_type'], $step['url'], $step['case_sensitive']))
                {
                    printDebug("Matched Goal Funnel " . $funnel['idfunnel'] . " Step " . $step['idstep'] . "(name: " . $step['name'] . ", url: " . $step['url']. "). ");
                    $serverTime = time();
                    $datetimeServer = Piwik_Tracker::getDatetimeFromTimestamp($serverTime);

                    // Look to see if this step has already been recorded for this visit 
                    $exists = Piwik_FetchOne("SELECT *
                        FROM ".Piwik_Common::prefixTable('log_funnel_step')." 
                        WHERE idsite = ? 
                        AND   idfunnel = ?
                        AND   idstep = ?
                        AND   idvisit = ?", 
                        array($idSite, $funnel['idfunnel'], $step['idstep'], $idVisit));

                    // Record it if not
                    if (!$exists){                      
                        printDebug("Recording...");
                        Piwik_Query("INSERT INTO " . Piwik_Common::prefixTable('log_funnel_step') . "
                            (idvisit, idsite, idaction_url, url, 
                            idgoal, idfunnel, idstep, 
                            idaction_url_ref, server_time)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                array($idVisit, $idSite, $idActionUrl, $url, 
                                $funnel['idgoal'], $step['idfunnel'],$step['idstep'],
                                $idRefererAction, $datetimeServer));
                    }
                }
            }
        }
    }

    function includeManualURLGoal($notification) {

        if (!Piwik_Common::getRequestVar('token_auth', false)) {
            return;
        }

        if (!Piwik_Common::getRequestVar('cid', false)) {
            return;
        }

        if (!Piwik_Common::getRequestVar('idgoal', false)) {
            return;
        }

        if (!Piwik_Common::getRequestVar('idsite', false)) {
            return;
        }

        $excluded =& $notification->getNotificationObject();
        $excluded = false;

    }
    function addMenus()
    {
        $idSite = Piwik_Common::getRequestVar('idSite');
     	$funnels = Piwik_Funnels_API::getInstance()->getFunnels($idSite);
        $goalsWithoutFunnels = Piwik_Funnels_API::getInstance()->getGoalsWithoutFunnels($idSite);
        if(count($funnels) == 0 && count($goalsWithoutFunnels) > 0)
        {	
            Piwik_AddMenu('Funnels', Piwik_Translate('Funnels_AddNewFunnel') , array('module' => 'Funnels', 'action' => 'addNewFunnel'));
        } else {
            Piwik_AddMenu('Funnels_Funnels', 'Funnels_Overview', array('module' => 'Funnels'));	
            foreach($funnels as $funnel) 
            {
                Piwik_AddMenu('Funnels_Funnels', str_replace('%', '%%', $funnel['goal_name']), array('module' => 'Funnels', 'action' => 'funnelReport', 'idFunnel' => $funnel['idfunnel']));
            }

        }
    }
    
    function archiveDay( $notification )
    {
        /**
         * @var Piwik_ArchiveProcessing_Day 
         */
        $archiveProcessing = $notification->getNotificationObject();
        $funnelDefinitions = Piwik_Funnels_API::getInstance()->getFunnels($archiveProcessing->idsite);
        $funnelDefinitions = $this->initializeStepData($funnelDefinitions);
    
        list($funnelDefinitions, $total) = $this->storeRefAndNextUrls($funnelDefinitions, $archiveProcessing);

        // Add the calculations of dropout
        foreach($funnelDefinitions as $funnelDefinition) 
        {
            $last_index = count($funnelDefinition['steps']) - 1;
            $idFunnel = $funnelDefinition['idfunnel'];
            $idGoal = $funnelDefinition['idgoal'];
    
            // get the goal conversions grouped by the converting action
            $goal_query = $archiveProcessing->queryConversionsByDimension('idaction_url', '%s.idgoal = '.$idGoal);
            $goalConversions = array();
            while($row = $goal_query->fetch() )
            {
                if ($row['label'] == null) {
                    // A 'manual' conversion is changed to have an 
                    // idaction_url of the magic number
                    $goalConversions[Piwik_Funnels::INDEX_MANUAL_CONVERSION] = $row[Piwik_Archive::INDEX_GOAL_NB_CONVERSIONS];
                } else {
                    $goalConversions[$row['label']] = $row[Piwik_Archive::INDEX_GOAL_NB_CONVERSIONS];
                }
            }

            for ($i = 0;$i <= $last_index; $i++) {
                $current_step = &$funnelDefinition['steps'][$i];
                $idStep = $current_step['idstep'];
                
                // record number of actions for the step
                $recordName = Piwik_Funnels::getRecordName('nb_actions', $idFunnel, $idStep);
                $archiveProcessing->insertNumericRecord($recordName, $current_step['nb_actions']);

                # Remove the previous step urls from the idaction_url_ref array and add their actions to the 
                # count of actions moving from the previous step to this one
                if ($i > 0)
                {
                    $previous_step = $funnelDefinition['steps'][$i-1];	
                    $nb_prev_step_actions = 0;
                    foreach ($previous_step['idaction_url'] as $key => $value)
                    {
                        if (isset($current_step['idaction_url_ref'][$key]))
                        {
                            $nb_prev_step_actions += $current_step['idaction_url_ref'][$key]['value'];
                            unset($current_step['idaction_url_ref'][$key]);
                        }
                        
                    }
                    $recordName = Piwik_Funnels::getRecordName('nb_next_step_actions', $idFunnel, $previous_step['idstep']);
                    $archiveProcessing->insertNumericRecord($recordName, $nb_prev_step_actions);
                    // calculate a percent of people continuing from the previous step to this
                    $recordName = Piwik_Funnels::getRecordName('percent_next_step_actions', $idFunnel, $previous_step['idstep']);
                    $archiveProcessing->insertNumericRecord($recordName, $this->percent($nb_prev_step_actions, $previous_step['nb_actions']));
                    
                    
                }
                if ($i < $last_index)
                {
                    
                    $next_step = $funnelDefinition['steps'][$i+1];	
                    # Remove this step's next actions that are actions for the next funnel step
                    foreach ($current_step['idaction_url_next'] as $key => $value)
                    {
                        if (isset($next_step['idaction_url'][$key]))
                        {
                            unset($current_step['idaction_url_next'][$key]);
                        }
                        
                    }
                    
                    # Archive the next urls that aren't funnel steps
                    $idActionNext = new Piwik_DataTable();
                    $exitCount = 0;
                    foreach($current_step['idaction_url_next'] as $id => $data)
                    {
                        $idActionNext->addRowFromSimpleArray($data);
                        $exitCount += $data['value'];
                    }
                    $recordName = Piwik_Funnels::getRecordName('idaction_url_next', $idFunnel, $idStep);
                    $archiveProcessing->insertBlobRecord($recordName, $idActionNext->getSerialized());
                    destroy($idActionNext);		

                    # and a sum of exit actions
                    $recordName = Piwik_Funnels::getRecordName('nb_exit', $idFunnel, $idStep);
                    $archiveProcessing->insertNumericRecord($recordName, $exitCount);
                    
                }
                
                // Archive the referring urls that aren't funnel steps
                $idActionRef = new Piwik_DataTable();
                $entryCount = 0;
                foreach($current_step['idaction_url_ref'] as $id => $data)
                {
                    $idActionRef->addRowFromSimpleArray($data);
                    $entryCount += $data['value'];
                }
                $recordName = Piwik_Funnels::getRecordName('idaction_url_ref', $idFunnel, $idStep);
                $archiveProcessing->insertBlobRecord($recordName, $idActionRef->getSerialized());
                destroy($idActionRef);	
                
                # and a sum of entry actions
                $recordName = Piwik_Funnels::getRecordName('nb_entry', $idFunnel, $idStep);
                $archiveProcessing->insertNumericRecord($recordName, $entryCount);

            }
        
            // For the last step, the comparison is the goal itself
            $last_step = $funnelDefinition['steps'][$last_index];
            $idStep = $last_step['idstep'];

            $recordName = Piwik_Funnels::getRecordName('nb_next_step_actions', $idFunnel, $idStep);
            $nb_goal_actions = 0;
            
            foreach ($goalConversions as $key => $value)
            {
                if (isset($last_step['idaction_url_next'][$key]))
                {
                    $nb_goal_actions += $last_step['idaction_url_next'][$key]['value'];
                    unset($last_step['idaction_url_next'][$key]);
                }
            }
            
            $archiveProcessing->insertNumericRecord($recordName, $nb_goal_actions);
                        
            $recordName = Piwik_Funnels::getRecordName('percent_next_step_actions', $idFunnel, $idStep);
            $archiveProcessing->insertNumericRecord($recordName, $this->percent($nb_goal_actions, $last_step['nb_actions']));
        
            # Archive the next urls that aren't funnel steps
            $idActionNext = new Piwik_DataTable();
            $exitCount = 0;
            foreach($last_step['idaction_url_next'] as $id => $data)
            {
                $idActionNext->addRowFromSimpleArray($data);
                $exitCount += $data['value'];
            }
            $recordName = Piwik_Funnels::getRecordName('idaction_url_next', $idFunnel, $idStep);
            $archiveProcessing->insertBlobRecord($recordName, $idActionNext->getSerialized());
            destroy($idActionNext);		

            # and a sum of exit actions
            $recordName = Piwik_Funnels::getRecordName('nb_exit', $idFunnel, $idStep);
            $archiveProcessing->insertNumericRecord($recordName, $exitCount);
        
            // Archive the total funnel actions
            $recordName = Piwik_Funnels::getRecordName('nb_actions', $idFunnel, false);
            $archiveProcessing->insertNumericRecord($recordName, $total);

            // What percent of people who visited the first funnel step converted at the end of the funnel?
            $recordName = Piwik_Funnels::getRecordName('conversion_rate', $idFunnel, false);
            $archiveProcessing->insertNumericRecord($recordName, $this->percent($nb_goal_actions, $funnelDefinition['steps'][0]['nb_actions']));
            
        }
        
    }
    
    function archivePeriod( $notification )
    {
        $archiveProcessing = $notification->getNotificationObject();
        $funnelMetricsToSum = array( 'nb_actions' );
        $stepMetricsToSum = array( 'nb_actions', 'nb_next_step_actions', 'nb_entry', 'nb_exit');
        $stepDataTables = array( 'idaction_url_next', 'idaction_url_ref');
        $funnels =  Piwik_Funnels_API::getInstance()->getFunnels($archiveProcessing->idsite);
        $fieldsToSum = array();
        $tablesToSum = array();

        foreach($funnels as $funnel)
        {
            $idFunnel = $funnel['idfunnel'];
            foreach($funnelMetricsToSum as $metricName)
            {
                $fieldsToSum[] = self::getRecordName($metricName, $idFunnel, false);
            }
            foreach($funnel['steps'] as $step)
            {
                $idStep = $step['idstep'];
                foreach($stepMetricsToSum as $metricName)
                {
                    $fieldsToSum[] = self::getRecordName($metricName, $idFunnel, $idStep);
                }
                foreach($stepDataTables as $dataTable)
                {
                    $tablesToSum[] = self::getRecordName($dataTable, $idFunnel, $idStep);
                }
            }
        }
        $numeric_records = $archiveProcessing->archiveNumericValuesSum($fieldsToSum);
        $rows_to_keep = Piwik_Funnels_Controller::NUM_URLS_TO_DISPLAY+2;
        $blob_records = $archiveProcessing->archiveDataTable($tablesToSum, null, $rows_to_keep, null, 'value');
        
        // also recording percent for each step going to next step, 
        // conversion rate for funnel
        foreach($funnels as $funnel)
        {
            $idFunnel = $funnel['idfunnel'];
            $i = 0;
            $funnel_start_actions = 0;
            foreach($funnel['steps'] as $step)
            {
                $idStep = $step['idstep'];
                $nb_actions = $numeric_records[self::getRecordName('nb_actions', $idFunnel, $idStep)];
                if ($i == 0) $funnel_start_actions = $nb_actions;
                $nb_next_step_actions = $numeric_records[self::getRecordName('nb_next_step_actions', $idFunnel, $idStep)];
                $percent_next_step_actions = $this->percent($nb_next_step_actions, $nb_actions);
                $recordName = self::getRecordName('percent_next_step_actions', $idFunnel, $idStep);
                $archiveProcessing->insertNumericRecord($recordName, $percent_next_step_actions);
                $i++;
            }
            $recordName = Piwik_Funnels::getRecordName('conversion_rate', $idFunnel, false);
            $archiveProcessing->insertNumericRecord($recordName, $this->percent($nb_next_step_actions, $funnel_start_actions));
            
        }
        
    }
    
    /**
     * @throws Exception if non-recoverable error
     */
    public function install()
    {
        $funnels_table_spec	 = "`idsite` int(11) NOT NULL,
                               `idgoal` int(11) NOT NULL,
                    		   `idfunnel` int(11) NOT NULL, 
         					   `deleted` tinyint(4) NOT NULL default '0',
                              	PRIMARY KEY  (`idsite`,`idgoal`, `idfunnel`) ";
        self::createTable('funnel', $funnels_table_spec);
  
        $funnel_steps_table_spec = "`idsite` int(11) NOT NULL,
                                    `idfunnel` int(11) NOT NULL, 
                         			`idstep` int(11) NOT NULL, 
                         			`name` varchar(255) NOT NULL,
                                    `match_attribute` varchar(20) NOT NULL,
                         			`url` text NOT NULL,
                                    `pattern_type` varchar(10) NOT NULL,
                                    `case_sensitive` tinyint(4) NOT NULL,
                          			`deleted` tinyint(4) NOT NULL default '0',
                         			PRIMARY KEY  (`idfunnel`, `idsite`, `idstep`) ";
        self::createTable('funnel_step', $funnel_steps_table_spec);
        
        $log_table_spec = "`idvisit` int(11) NOT NULL,
                          `idsite` int(11) NOT NULL,
                    	  `server_time` datetime NOT NULL,
                    	  `idaction_url` int(11) default NULL,
                          `idaction_url_ref` int(11) default NULL,
                          `idaction_url_next` int(11) default NULL,
                    	  `url` text NOT NULL,
                    	  `idgoal` int(11) NOT NULL,
                    	  `idfunnel` int(11) NOT NULL, 
                    	  `idstep` int(11) NOT NULL, 
                    	  PRIMARY KEY  (`idvisit`, `idfunnel`, `idstep`),
                    	  INDEX index_idsite_datetime ( idsite, server_time ), 
                          INDEX index_idsite_idvisit_idaction_url_idaction_url_next 
                            (idsite, idvisit, idaction_url, idaction_url_next), 
                          INDEX index_idsite_idfunnel_idstep_idvisit
                            (idsite, idfunnel, idstep, idvisit)";
        self::createTable('log_funnel_step', $log_table_spec);
    }
    
    /**
     * @throws Exception if non-recoverable error
     */
    public function uninstall()
    {
        $sql = "DROP TABLE ". Piwik_Common::prefixTable('funnel') ;
        Piwik_Exec($sql);    
        $sql =  "DROP TABLE ". Piwik_Common::prefixTable('funnel_step') ;
        Piwik_Exec($sql);  
        $sql =  "DROP TABLE ". Piwik_Common::prefixTable('log_funnel_step') ;
        Piwik_Exec($sql);
    }

    function createTable( $tablename, $spec ) 
    {
        $sql = "CREATE TABLE IF NOT EXISTS ". Piwik_Common::prefixTable($tablename)." ( $spec )  DEFAULT CHARSET=utf8 " ;
        Piwik_Exec($sql);
    }
    
    protected function queryFunnelSteps( $archiveProcessing )
    {
        $query = "SELECT idstep, idfunnel, idaction_url_ref, idaction_url, idaction_url_next, 
                 url_ref.name as idaction_url_ref_name, url.name as idaction_url_name, 
                 url_next.name as idaction_url_next_name,
                 count(*) as nb_actions
             	FROM ".Piwik_Common::prefixTable('log_funnel_step')." as log_funnel_step
                LEFT JOIN ".Piwik_Common::prefixTable('log_action')." as url_ref on url_ref.idaction = log_funnel_step.idaction_url_ref
                LEFT JOIN ".Piwik_Common::prefixTable('log_action')." as url on url.idaction = log_funnel_step.idaction_url
                LEFT JOIN ".Piwik_Common::prefixTable('log_action')." as url_next on url_next.idaction = log_funnel_step.idaction_url_next
             	WHERE server_time >= ?
                        AND server_time <= ?
             			AND idsite = ?
                GROUP BY idstep, idfunnel, idaction_url_ref, idaction_url, idaction_url_next, 
                idaction_url_ref_name, idaction_url_name, idaction_url_next_name
                ORDER BY NULL";
        $query = $archiveProcessing->db->query($query, array( $archiveProcessing->getStartDatetimeUTC(), $archiveProcessing->getEndDatetimeUTC(), $archiveProcessing->idsite ));
        return $query;
    }
    
    protected function initializeStepData( $funnelDefinitions )
    {
        
        // Initialize all step actions to zero
        foreach($funnelDefinitions as &$funnelDefinition) 
        {
            
            foreach($funnelDefinition['steps'] as &$step)
            {
                $step['nb_actions'] = 0;
                $step['nb_next_step_actions'] = 0;
                $step['percent_next_step_actions'] = 0;
                $step['idaction_url'] = array();
                $step['idaction_url_ref'] = array();
                $step['idaction_url_next'] = array();
            }
        }
        return $funnelDefinitions;
    }
    
    protected function storeRefAndNextUrls( $funnelDefinitions, $archiveProcessing )
    {
        $total = 0;
        // Sum the actions recorded for each funnel step, and store arrays of 
        // the refering and next urls
        $query = $this->queryFunnelSteps($archiveProcessing);
        while( $row = $query->fetch() )
        {
            $idfunnel = $row['idfunnel'];
            $idstep = $row['idstep'];
            $funnelDefinition = &$funnelDefinitions[$idfunnel];
            $url_fields = array(
                'idaction_url_ref' => array(
                    'id' => $row['idaction_url_ref'], 
                    'label' => $row['idaction_url_ref_name']
                ),
                'idaction_url' => array(
                    'id' => $row['idaction_url'],     
                    'label' => $row['idaction_url_name']
                ),
                'idaction_url_next' => array(
                    'id' => $row['idaction_url_next'], 
                    'label' => $row['idaction_url_next_name']
                )
            );

            foreach ($funnelDefinition['steps'] as &$step) 
            {
                if ($step['idstep'] != $idstep){
                    continue;
                }
                    
                // increment the number of actions for the step by the number in this row (this is a sum of 
                // all actions for the step that have the same referring and next urls)
                $step['nb_actions'] += $row['nb_actions'];
                $total += $row['nb_actions'];

                // store the total number of actions coming from each entry url and going to each exit url 
                foreach ($url_fields as $key => $val)
                {
                    if (!isset($step[$key][$val['id']])){
                        // label for empty values need to be null rather than an empty string
                        // in order to be summed correctly
                        $step[$key][$val['id']] = array('value' => 0, 'label' => $this->labelOrDefault($val['label'], 'null'));
                    }
                    $step[$key][$val['id']]['value'] += $row['nb_actions'];	
                }
            }			
        }
        return array($funnelDefinitions, $total);
    }
    
    /**
     * @param string $recordName 'nb_actions'
     * @param int $idFunnel to return the metrics for, or false to return overall 
     * @param int $idStep to return the metrics for, or false to return overall for funnel
     * @return unknown
     */
    static public function getRecordName( $recordName, $idFunnel = false, $idStep = false )
    {
        $idFunnelStr = $idStepStr = '';
        if(!empty($idFunnel))
        {
            $idFunnelStr = $idFunnel . "_";
        }	
        if($idStep !== false)
        {
            $idStepStr = $idStep . '_';
        }
        return 'Funnels_' . $idFunnelStr . $idStepStr . $recordName;
    }
    
    /**
     * @param string $label
     * @param string default
     * @return $label 
     * Returns the default if the label is an empty string, otherwise returns the label itself
     */
    protected function labelOrDefault($label, $default)
    {
        if ($label == '')
        {
            return $default;
        }
        return $label;
    }
    
    function percent($amount, $total) {
        if ($total == 0) return 0;
        return 100 * $amount / $total;
    }
    /**
     * @param string $url The string to see if it matches
     * @param string $pattern_type One of 'regex', 'contains', or 'exact'
     * @param string $pattern The pattern to attempt to match
     * @param boolean $case_sensitive true to match in a case-sensitive way
     * @return boolean true if $url matches $pattern given the $pattern_type 
     * and $case_sensitive-ity
     */
    static public function isMatch($url, $pattern_type, $pattern, $case_sensitive = true) {

        $match = false;

        switch($pattern_type)
        {
        case 'regex':
            if(strpos($pattern, '/') !== false 
                && strpos($pattern, '\\/') === false)
            {
                $pattern = str_replace('/', '\\/', $pattern);
            }
            $pattern = '/' . $pattern . '/'; 
            if(!$case_sensitive)
            {
                $pattern .= 'i';
            }
            $match = (@preg_match($pattern, $url) == 1);
            break;
        case 'contains':
            if($case_sensitive)
            {
                $matched = strpos($url, $pattern);
            }
            else
            {
                $matched = stripos($url, $pattern);
            }
            $match = ($matched !== false);
            break;
        case 'exact':
            if($case_sensitive)
            {
                $matched = strcmp($pattern, $url);
            }
            else
            {
                $matched = strcasecmp($pattern, $url);
            }
            $match = ($matched == 0);
            break;
        default:
            throw new Exception(Piwik_TranslateException('General_ExceptionInvalidGoalPattern', array($pattern_type)));
            break;
        }

        return $match;
    }
}

