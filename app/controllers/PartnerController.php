<?php
require_once ('ReportFilterHelpers.php');
require_once ('models/table/OptionList.php');
require_once ('models/table/MultiAssignList.php');
require_once ('models/table/MultiOptionList.php');
require_once ('models/table/Location.php');
require_once ('views/helpers/FormHelper.php');
require_once ('views/helpers/DropDown.php');
require_once ('views/helpers/Location.php');
require_once ('views/helpers/CheckBoxes.php');
require_once ('views/helpers/TrainingViewHelper.php');
require_once ('models/table/Helper.php');

class PartnerController extends ReportFilterHelpers {
	public function init() {	}

	public function preDispatch() {
		parent::preDispatch ();

		if (! $this->isLoggedIn ())
			$this->doNoAccessError ();

		if (! $this->setting('module_employee_enabled'))
			$this->_redirect('select/select');
	}

	public function indexAction()
	{
		$this->_redirect('partner/search');
	}

	public function deleteAction() {
		if (! $this->hasACL ( 'edit_employee' )) {
			$this->doNoAccessError ();
		}

		require_once('models/table/Partner.php');
		$status = ValidationContainer::instance ();
		$id = $this->getSanParam ( 'id' );

		if ($id) {
			$partner = new Partner ( );
			$rows = $partner->find ( $id );
			$row = $rows->current ();
			if ($row) {
				$partner->delete ( 'id = ' . $row->id );
			}
			$status->setStatusMessage ( t ( 'That partner was deleted.' ) );
		} else {
			$status->setStatusMessage ( t ( 'That partner could not be found.' ) );
		}

		//validate
		$this->view->assign ( 'status', $status );

	}

	public function addAction() {
		$this->view->assign ( 'mode', 'add' );
		return $this->editAction ();
	}

	public function editAction() {
		if (! $this->hasACL ( 'edit_employee' )) {
			$this->doNoAccessError ();
		}

		$db     = $this->dbfunc();
		$status = ValidationContainer::instance ();
		$params = $this->getAllParams();
		$id     = $params['id'];

		// restricted access?? only show partners by organizers that we have the ACL to view // - removed 5/1/13, they dont want this, its used by site-rollup (datashare), and user-restrict by org.
		$org_allowed_ids = allowed_org_access_full_list($this); // doesnt have acl 'training_organizer_option_all'
		$site_orgs = allowed_organizer_in_this_site($this); // for sites to host multiple training organizers on one domain
		$siteOrgsClause = $site_orgs ? " AND partner.organizer_option_id IN ($site_orgs)" : "";
		if ($org_allowed_ids && $this->view->mode != 'add') {
			$validID = $db->fetchCol("SELECT partner.id FROM partner WHERE partner.id = $id AND partner.organizer_option_id in ($org_allowed_ids) $siteOrgsClause");
			if(empty($validID))
				$this->doNoAccessError ();
		
		}

		if ( $this->getRequest()->isPost() )
		{
			//validate then save
			$status->checkRequired ( $this, 'partner', t ( 'Partner' ) );
			if ($this->setting('display_partner_type'))
				$status->checkRequired ( $this, 'partner_type_option_id',         t ( 'Type of Partner' ) );
			$status->checkRequired ( $this, 'address1',                           t ( 'Address 1' ) );
			$status->checkRequired ( $this, 'city',                               t ( 'City' ) );
			$status->checkRequired ( $this, 'province_id',                        t ( 'Region A (Province)' ) );
			if ($this->setting('display_employee_funder')) {
				$endDateArr = $this->getSanParam('funding_end_date');
				if($this->_is_empty_input_array($endDateArr))
					$status->addError('funding_end_date', t ( 'Funding End Date' ) . space . t('is required'));
				$fundersArr = $this->getSanParam('partner_funder_option_id');
				if($this->_is_empty_input_array($fundersArr))
					$status->addError('partner_funder_option_id', t ( 'Funder' ) . space . t('is required'));
			}
			#if ($this->setting('display_employee_intended_transition'))
			#	$status->checkRequired ( $this, 'employee_transition_option_id',  t ( 'Intended Transition' ) );
			#$status->checkRequired ( $this, 'comments',                          t ( 'Partner Comments' ) );
			#$status->checkRequired ( $this, 'subpartner_id[]',                   t ( 'Sub Partner' ) );
			if ($this->setting('display_employee_agreement_end_date'))
				$status->checkRequired ( $this, 'agreement_end_date',             t ( 'Agreement End Date' ) );
			if ($this->setting('display_employee_importance'))
				$status->checkRequired ( $this, 'partner_importance_option_id',   t ( 'Importance' ) );
			$status->checkRequired ( $this, 'hr_contact_name',                    t ( 'HR Contact Person Name' ) );
			$status->checkRequired ( $this, 'hr_contact_phone',                   t ( 'HR Contact Office Phone' ) );
			#$status->checkRequired ( $this, 'hr_contact_fax',                     t ( 'HR Contact Office Fax' ) );
			$status->checkRequired ( $this, 'hr_contact_email',                   t ( 'HR Contact Email' ) );

			$params['funding_end_date'] = $this->_array_me($params['funding_end_date']);
			foreach ($params['funding_end_date'] as $i => $value)
				$params['funding_end_date'][$i] = $this->_euro_date_to_sql($value);
			$params['transition_confirmed'] = $params['transition_confirmed'] == 'on' ? 1 : 0;
			$params['agreement_end_date'] = $this->_euro_date_to_sql($params['agreement_end_date']);
			$params['subpartner_id'] = $this->_array_me($params['subpartner_id']);
			foreach ($params['subpartner_id'] as $i => $value) { // strip empty values (it breaks MultiOptionList apparently)
				if (empty($value))
					unset($params['subpartner_id'][$i]);
			}

			//location save stuff
			$params['location_id'] = regionFiltersGetLastID(null, $params); // formprefix, criteria
			if ( $params['city'] ) {
				$params['location_id'] = Location::insertIfNotFound ( $params['city'], $params['location_id'], $this->setting ( 'num_location_tiers' ) );
			}

			if (! $status->hasError() ) {
				$id = $this->_findOrCreateSaveGeneric('partner', $params);

				if(!$id) {
					$status->setStatusMessage( t('That partner could not be saved.') );
				} else {
					MultiOptionList::updateOptions ( 'partner_to_funder', 'partner_funder_option', 'partner_id', $id, 'partner_funder_option_id', $params['partner_funder_option_id'], 'funder_end_date', $params['funding_end_date'] );
					$db->query("DELETE FROM partner_to_subpartner WHERE partner_id = $id"); // updateOptions is not clearing the old options, I dont know why... todo
					MultiOptionList::updateOptions ( 'partner_to_subpartner', 'partner', 'partner_id', $id, 'subpartner_id', $params['subpartner_id'] );
					$status->setStatusMessage( t('The partner was saved.') );
					$this->_redirect("partner/edit/id/$id");
				}
			}
		}

		if ($id && ! $status->hasError()) { // read data from db

			// restricted access?? only show partners by organizers that we have the ACL to view
			$org_allowed_ids = allowed_org_access_full_list($this); // doesnt have acl 'training_organizer_option_all'
			$orgWhere = ($org_allowed_ids) ? " AND partner.organizer_option_id in ($org_allowed_ids) " : "";
			// restricted access?? only show organizers that belong to this site if its a multi org site
			$site_orgs = allowed_organizer_in_this_site($this); // for sites to host multiple training organizers on one domain
			$allowedWhereClause .= $site_orgs ? " AND partner.organizer_option_id in ($site_orgs) " : "";

			// continue reading data
			$sql = 'SELECT * FROM partner WHERE id = '.$id.space.$orgWhere;
			$row = $db->fetchRow( $sql );
			if (! $row)
				$status->setStatusMessage ( t('Error finding that record in the database.') );
			else
			{
				$params = $row; // reassign form data

				$region_ids = Location::getCityInfo($params['location_id'], $this->setting('num_location_tiers'));
				$params['city'] = $region_ids[0];
				$region_ids = Location::regionsToHash($region_ids);
				$params = array_merge($params, $region_ids);

				//get linked table data from option tables
				$sql = "SELECT partner_funder_option_id,funder_end_date FROM partner_to_funder WHERE partner_id = $id";
				$params['funder'] = $db->fetchAll($sql);

				$sql = "SELECT subpartner_id FROM partner_to_subpartner WHERE partner_id = $id";
				$params['subpartners'] = $db->fetchCol($sql);
			}
		}

		// make sure form data is valid for display
		if (empty($params['funder']))
			$params['funder'] = array(array());
		if (empty($params['subpartners']))
			$params['subpartners'] = array(' ');

		// assign form drop downs
		$this->view->assign( 'status', $status );
		$this->view->assign ( 'pageTitle', $this->view->mode == 'add' ? t ( 'Add Partner' ) : t( 'View Partner' ) );
		$this->viewAssignEscaped ( 'partner', $params );
		$this->viewAssignEscaped ( 'locations', Location::getAll () );
		$this->view->assign ( 'partners',    DropDown::generateHtml ( 'partner', 'partner', $params['partner_type_option_id'], false, $this->view->viewonly, false ) ); //table, col, selected_value
		$this->view->assign ( 'subpartners', DropDown::generateHtml ( 'partner', 'partner', 0, false, $this->view->viewonly, false, true, array('name' => 'subpartner_id[]'), true ) );
		$this->view->assign ( 'types',       DropDown::generateHtml ( 'partner_type_option', 'type_phrase', $params['partner_type_option_id'], false, $this->view->viewonly, false ) );
		$this->view->assign ( 'importance',  DropDown::generateHtml ( 'partner_importance_option', 'importance_phrase', $params['partner_importance_option_id'], false, $this->view->viewonly, false ) );
		$this->view->assign ( 'transitions', DropDown::generateHtml ( 'employee_transition_option', 'transition_phrase', $params['employee_transition_option_id'], false, $this->view->viewonly, false ) );
		$this->view->assign ( 'incomingPartners', DropDown::generateHtml ( 'partner', 'partner', $params['incoming_partner'], false, $this->view->viewonly, false, true, array('name' => 'incoming_partner'), true ) );
		$this->view->assign ( 'organizers',  DropDown::generateHtml ( 'training_organizer_option', 'training_organizer_phrase', $params['organizer_option_id'], false, $this->view->viewonly, false, true, array('name' => 'organizer_option_id'), true ) );
		$helper = new Helper();
		$this->viewAssignEscaped ( 'facilities', $helper->getFacilities() );
	}

	public function searchAction()
	{
		if (! $this->hasACL ( 'edit_employee' )) {
			$this->doNoAccessError ();
		}

		$criteria = $this->getAllParams();

		if ($criteria['go'])
		{
			// process search
			$where = array();
			list($a, $location_tier, $location_id) = $this->getLocationCriteriaValues($criteria);
			list($locationFlds, $locationsubquery) = Location::subquery($this->setting('num_location_tiers'), $location_tier, $location_id, true);
			$sql = "SELECT DISTINCT
					partner.id,partner.partner,partner.location_id,".implode(',',$locationFlds)."
					,GROUP_CONCAT(funderopt.funder_phrase) as funder
					,GROUP_CONCAT(funders.funder_end_date) as funding_end_date
					,GROUP_CONCAT(subp.partner) as subpartners
					FROM partner LEFT JOIN ($locationsubquery) as l  ON l.id = partner.location_id
					LEFT JOIN partner_to_funder funders         ON partner.id = funders.partner_id
					LEFT JOIN partner_funder_option funderopt   ON funders.partner_funder_option_id = funderopt.id
					LEFT JOIN partner_to_subpartner subpartners ON subpartners.partner_id = partner.id
					LEFT JOIN partner subp                      ON subp.id = subpartners.subpartner_id 
					LEFT JOIN location parent_loc               ON parent_loc.id = partner.location_id";

			// restricted access?? only show partners by organizers that we have the ACL to view
			$org_allowed_ids = allowed_org_access_full_list($this); // doesnt have acl 'training_organizer_option_all'
			if($org_allowed_ids)
				$where[] = " partner.organizer_option_id in ($org_allowed_ids) ";
			// restricted access?? only show organizers that belong to this site if its a multi org site
			$site_orgs = allowed_organizer_in_this_site($this); // for sites to host multiple training organizers on one domain
			if ($site_orgs)
				$where[] = " partner.organizer_option_id in ($site_orgs) ";

			if ($locationWhere = $this->getLocationCriteriaWhereClause($criteria, '', '')) {
				#$where[] = $locationWhere;
				$where[] = "($locationWhere OR parent_loc.parent_id = $location_id)"; #todo the subquery and parent_id is not working
			}

#			if ($location_id && $alsoCheckMultiRegions = Location::southAfrica_get_multi_region($location_id)) //#SAONLY - check if they are using the *Multiple Regions* items
#				$where[] = " partner.location_id in ($alsoCheckMultiRegions)";

			if ($criteria['subpartner_id'])     $where[] = 'subpartners.subpartner_id = '.$criteria['subpartner_id'];
			if ($criteria['partner_id'])        $where[] = 'partner.id = '.$criteria['partner_id'];
			if ($criteria['start_date'])        $where[] = 'funder_end_date >= \''.$this->_euro_date_to_sql( $criteria['start_date'] ) .' 00:00:00\'';
			if ($criteria['end_date'])          $where[] = 'funder_end_date <= \''.$this->_euro_date_to_sql( $criteria['end_date'] ) .' 23:59:59\'';
			if ( count ($where) )
				$sql .= ' WHERE ' . implode(' AND ', $where);
			$sql .= ' GROUP BY partner.id ';

			$db = $this->dbfunc();
			$rows = $db->fetchAll( $sql );

			$locations = Location::getAll (); // used in view also
			// hack #TODO - seems Region A -> ASDF, Region B-> *Multiple Province*, Region C->null Will not produce valid locations with Location::subquery
			foreach ($rows as $i => $row) {
				if ($row['province_id'] == ""){ // empty province
					$updatedRegions = Location::getCityandParentNames($row['location_id'], $locations, $this->setting('num_location_tiers'));
					$rows[$i] = array_merge($row, $updatedRegions);
				}
			}

			$this->viewAssignEscaped('results', $rows);
			$this->view->assign ('count', count($rows) );

			if ($criteria ['outputType']) {
				foreach($rows as $i => $row) {
					unset($rows[$i]['city_id']);
					unset($rows[$i]['location_id']);
					unset($rows[$i]['province_id']);
					unset($rows[$i]['district_id']);
					unset($rows[$i]['region_c_id']);
					unset($rows[$i]['region_d_id']);
					unset($rows[$i]['region_e_id']);
					unset($rows[$i]['region_f_id']);
					unset($rows[$i]['region_g_id']);
					unset($rows[$i]['region_h_id']);
					unset($rows[$i]['region_i_id']);
				}
				$this->sendData ( $this->reportHeaders ( false, $rows ) );
			}
		}
		// assign form drop downs
		$this->view->assign('status', $status);
		$this->viewAssignEscaped ( 'criteria', $criteria );
		$this->viewAssignEscaped ( 'locations', $locations );
		$this->view->assign ( 'partners',    DropDown::generateHtml ( 'partner', 'partner', $criteria['partner_id'], false, $this->view->viewonly, false ) );
		$this->view->assign ( 'subpartners', DropDown::generateHtml ( 'partner', 'partner', $criteria['subpartner_id'], false, $this->view->viewonly, false, true, array('name' => 'subpartner_id'), true ) );
	}
}

?>