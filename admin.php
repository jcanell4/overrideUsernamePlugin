<?php
/*
 *  User Manager
 *
 *  Dokuwiki Admin Plugin
 *
 *  This version of the user manager has been modified to only work with
 *  objectified version of auth system
 *
 *  @author  neolao <neolao@neolao.com>
 *  @author  Chris Smith <chris@jalakai.co.uk>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN_IMAGES')) define('DOKU_PLUGIN_IMAGES',DOKU_BASE.'lib/plugins/usermanager/images/');

/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_usermanager extends DokuWiki_Admin_Plugin {

    protected $_auth = null;        // auth object
    protected $_user_total = 0;     // number of registered users
    protected $_filter = array();   // user selection filter(s)
    protected $_start = 0;          // index of first user to be displayed
    protected $_last = 0;           // index of the last user to be displayed
    protected $_pagesize = 20;      // number of users to list on one page
    protected $_edit_user = '';     // set to user selected for editing
    protected $_edit_userdata = array();
    protected $_disabled = '';      // if disabled set to explanatory string
    protected $_import_failures = array();
    protected $_lastdisabled = false; // set to true if last user is unknown and last button is hence buggy

    /**
     * Constructor
     */
    public function __construct(){
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;
        $this->setupLocale();

        if (!isset($auth)) {
            $this->_disabled = $this->lang['noauth'];
        } else if (!$auth->canDo('getUsers')) {
            $this->_disabled = $this->lang['nosupport'];
        } else {
            // we're good to go
            $this->_auth = & $auth;
        }

        // attempt to retrieve any import failures from the session
        if (!empty($_SESSION['import_failures'])){
            $this->_import_failures = $_SESSION['import_failures'];
        }
    }

    //[START: IOC]    
//    /**
//     * Return prompt for admin menu
//     *
//     * @param string $language
//     * @return string
//     */
//    public function getMenuText($language) {
//
//        if (!is_null($this->_auth))
//          return parent::getMenuText($language);
//
//        return $this->getLang('menu').' '.$this->_disabled;
//    }
    
    /**
     * Return prompt for admin menu
     * @param string $language
     * @return string
     */
    public function getMenuText($language) {
        if (!is_null($this->_auth)) {
          return parent::getMenuText($language);
        }else {
          return $this->getLang('menu').' '.$this->_disabled;
        }
    }
    //[END: IOC]

    /**
     * return sort order for position in admin menu
     *
     * @return int
     */
    public function getMenuSort() {
        return 2;
    }

    /**
     * @return int current start value for pageination
     */
    public function getStart() {
        return $this->_start;
    }

    /**
     * @return int number of users per page
     */
    public function getPagesize() {
        return $this->_pagesize;
    }

    /**
     * @param boolean $lastdisabled
     */
    public function setLastdisabled($lastdisabled) {
        $this->_lastdisabled = $lastdisabled;
    }

    /**
     * Handle user request
     *
     * @return bool
     */
    public function handle() {
        global $INPUT;
        if (is_null($this->_auth)) return false;
	//[START: IOC]
        $ret = TRUE;
        //[END: IOC]
	
        // extract the command and any specific parameters
        // submit button name is of the form - fn[cmd][param(s)]
        $fn = $INPUT->param('fn');

        if (is_array($fn)) {
            $cmd = key($fn);
            $param = is_array($fn[$cmd]) ? key($fn[$cmd]) : null;
        } else {
            $cmd = $fn;
            $param = null;
        }

        if ($cmd != "search") {
            $this->_start = $INPUT->int('start', 0);
            $this->_filter = $this->_retrieveFilter();
        }

        switch($cmd){
	//[START: IOC]
//	      case "add"    : $this->_addUser(); break;
//            case "delete" : $this->_deleteUser(); break;
//            case "modify" : $this->_modifyUser(); break;
//            case "edit"   : $this->_editUser($param); break;
	    
            case "add"    : $ret = $this->_addUser(); break;
            case "delete" : $ret = $this->_deleteUser(); break;
            case "modify" : $ret = $this->_modifyUser(); break;
            case "edit"   : $ret = $this->_editUser($param); break;
    	//[END: IOC]
            case "search" : $this->_setFilter($param);
                            $this->_start = 0;
                            break;
            case "export" : $this->_export(); break;
            case "import" : $this->_import(); break;
            case "importfails" : $this->_downloadImportFailures(); break;
        }

        $this->_user_total = $this->_auth->canDo('getUserCount') ? $this->_auth->getUserCount($this->_filter) : -1;

        // page handling
        switch($cmd){
            case 'start' : $this->_start = 0; break;
            case 'prev'  : $this->_start -= $this->_pagesize; break;
            case 'next'  : $this->_start += $this->_pagesize; break;
            case 'last'  : $this->_start = $this->_user_total; break;
        }
        $this->_validatePagination();
	//[START: IOC]
	//return true;
        return $ret;
	//[END: IOC]
    }

    /**
     * Output appropriate html
     *
     * @return bool
     */
    public function html() {
        global $ID;

        if(is_null($this->_auth)) {
            print $this->lang['badauth'];
            return false;
        }

        $user_list = $this->_auth->retrieveUsers($this->_start, $this->_pagesize, $this->_filter);

        $page_buttons = $this->_pagination();
        $delete_disable = $this->_auth->canDo('delUser') ? '' : 'disabled="disabled"';

        $editable = $this->_auth->canDo('UserMod');
        $export_label = empty($this->_filter) ? $this->lang['export_all'] : $this->lang['export_filtered'];

        print $this->locale_xhtml('intro');
        print $this->locale_xhtml('list');

        ptln("<div id=\"user__manager\">");
        ptln("<div class=\"level2\">");

        if ($this->_user_total > 0) {
            ptln("<p>".sprintf($this->lang['summary'],$this->_start+1,$this->_last,$this->_user_total,$this->_auth->getUserCount())."</p>");
        } else {
            if($this->_user_total < 0) {
                $allUserTotal = 0;
            } else {
                $allUserTotal = $this->_auth->getUserCount();
            }
            ptln("<p>".sprintf($this->lang['nonefound'], $allUserTotal)."</p>");
        }
        ptln("<form action=\"".wl($ID)."\" method=\"post\">");
        formSecurityToken();
        ptln("  <div class=\"table\">");
        ptln("  <table class=\"inline\">");
        ptln("    <thead>");
        ptln("      <tr>");
	//[START: IOC]
        //ptln("        <th>&#160;</th><th>".$this->lang["user_id"]."</th><th>".$this->lang["user_name"]."</th><th>".$this->lang["user_mail"]."</th><th>".$this->lang["user_groups"]."</th>");
        ptln("        <th>&#160;</th>
                      <th>".$this->lang["user_id"]."</th>
                      <th>".$this->lang["user_name"]."</th>
                      <th>".$this->lang["user_mail"]."</th>
                      <th>".$this->lang["user_moodle"]."</th>
                      <th>".$this->lang["user_editor"]."</th>
                      <th>".$this->lang["user_groups"]."</th>");
        //[END: IOC]
        ptln("      </tr>");

        ptln("      <tr>");
        ptln("        <td class=\"rightalign\"><input type=\"image\" src=\"".DOKU_PLUGIN_IMAGES."search.png\" name=\"fn[search][new]\" title=\"".$this->lang['search_prompt']."\" alt=\"".$this->lang['search']."\" class=\"button\" /></td>");
        ptln("        <td><input type=\"text\" name=\"userid\" class=\"edit\" value=\"".$this->_htmlFilter('user')."\" /></td>");
        ptln("        <td><input type=\"text\" name=\"username\" class=\"edit\" value=\"".$this->_htmlFilter('name')."\" /></td>");
        ptln("        <td><input type=\"text\" name=\"usermail\" class=\"edit\" value=\"".$this->_htmlFilter('mail')."\" /></td>");
        //[START: IOC]
	$checked = ($this->_htmlFilter('moodle')) ? " checked" : "";
        ptln("        <td><input type=\"checkbox\" name=\"usermoodle\" $checked /></td>");
        ptln("        <td><input type=\"text\" name=\"usereditor\" class=\"edit\" value=\"".$this->_htmlFilter('editor')."\" /></td>");
	//[END: IOC]
        ptln("        <td><input type=\"text\" name=\"usergroups\" class=\"edit\" value=\"".$this->_htmlFilter('grps')."\" /></td>");
        ptln("      </tr>");
        ptln("    </thead>");

        if ($this->_user_total) {
            ptln("    <tbody>");
            foreach ($user_list as $user => $userinfo) {
                extract($userinfo);
                /**
                 * @var string $name
                 * @var string $pass
                 * @var string $mail
		 //[START: IOC]
                 * @var string $moodle
                 * @var string $editor
		 //[END: IOC]
                 * @var array  $grps
                 */
                $groups = join(', ',$grps);
                ptln("    <tr class=\"user_info\">");
                ptln("      <td class=\"centeralign\"><input type=\"checkbox\" name=\"delete[".hsc($user)."]\" ".$delete_disable." /></td>");
                if ($editable) {
                    ptln("    <td><a href=\"".wl($ID,array('fn[edit]['.$user.']' => 1,
                                                           'do' => 'admin',
                                                           'page' => 'usermanager',
                                                           'sectok' => getSecurityToken())).
                         "\" title=\"".$this->lang['edit_prompt']."\">".hsc($user)."</a></td>");
                } else {
                    ptln("    <td>".hsc($user)."</td>");
                }
		//[START: IOC]
		//ptln("      <td>".hsc($name)."</td><td>".hsc($mail)."</td><td>".hsc($groups)."</td>");
                ptln("      <td>".hsc($name)."</td>
                            <td>".hsc($mail)."</td>
                            <td>&nbsp;".$moodle."</td>
                            <td>".$editor."</td>
                            <td>".hsc($groups)."</td>");
		//[END: IOC]
                ptln("    </tr>");
            }
            ptln("    </tbody>");
        }

        ptln("    <tbody>");
        ptln("      <tr><td colspan=\"5\" class=\"centeralign\">");
        ptln("        <span class=\"medialeft\">");
        ptln("          <button type=\"submit\" name=\"fn[delete]\" id=\"usrmgr__del\" ".$delete_disable.">".$this->lang['delete_selected']."</button>");
        ptln("        </span>");
        ptln("        <span class=\"mediaright\">");
        ptln("          <button type=\"submit\" name=\"fn[start]\" ".$page_buttons['start'].">".$this->lang['start']."</button>");
        ptln("          <button type=\"submit\" name=\"fn[prev]\" ".$page_buttons['prev'].">".$this->lang['prev']."</button>");
        ptln("          <button type=\"submit\" name=\"fn[next]\" ".$page_buttons['next'].">".$this->lang['next']."</button>");
        ptln("          <button type=\"submit\" name=\"fn[last]\" ".$page_buttons['last'].">".$this->lang['last']."</button>");
        ptln("        </span>");
        if (!empty($this->_filter)) {
            ptln("    <button type=\"submit\" name=\"fn[search][clear]\">".$this->lang['clear']."</button>");
        }
        ptln("        <button type=\"submit\" name=\"fn[export]\">".$export_label."</button>");
        ptln("        <input type=\"hidden\" name=\"do\"    value=\"admin\" />");
        ptln("        <input type=\"hidden\" name=\"page\"  value=\"usermanager\" />");

        $this->_htmlFilterSettings(2);

        ptln("      </td></tr>");
        ptln("    </tbody>");
        ptln("  </table>");
        ptln("  </div>");

        ptln("</form>");
        ptln("</div>");

        $style = $this->_edit_user ? " class=\"edit_user\"" : "";

        if ($this->_auth->canDo('addUser')) {
            ptln("<div".$style.">");
            print $this->locale_xhtml('add');
            ptln("  <div class=\"level2\">");

            $this->_htmlUserForm('add',null,array(),4);

            ptln("  </div>");
            ptln("</div>");
        }

        if($this->_edit_user  && $this->_auth->canDo('UserMod')){
            ptln("<div".$style." id=\"scroll__here\">");
            print $this->locale_xhtml('edit');
            ptln("  <div class=\"level2\">");

            $this->_htmlUserForm('modify',$this->_edit_user,$this->_edit_userdata,4);

            ptln("  </div>");
            ptln("</div>");
        }

        if ($this->_auth->canDo('addUser')) {
            $this->_htmlImportForm();
        }
        ptln("</div>");
        return true;
    }

    /**
     * Display form to add or modify a user
     *
     * @param string $cmd 'add' or 'modify'
     * @param string $user id of user
     * @param array  $userdata array with name, mail,[START: IOC] moodle, editor, [END: IOC] pass, grps
     * @param int    $indent
     */
    protected function _htmlUserForm($cmd, $user='', $userdata=array(), $indent=0) {
        global $conf;
        global $ID;
        //[START: IOC]
	//$name = $mail = $groups = '';
        $name = $mail = $groups = $editor = '';
        $moodle = ($this->_auth->canDo("modMoodle")) ? '1' : '0';
	//[END: IOC]
        $notes = array();

        if ($user) {
            extract($userdata);
            if (!empty($grps)) $groups = join(',',$grps);
        } else {
            $notes[] = sprintf($this->lang['note_group'],$conf['defaultgroup']);
        }

        ptln("<form action=\"".wl($ID)."\" method=\"post\">",$indent);
        formSecurityToken();
        ptln("  <div class=\"table\">",$indent);
        ptln("  <table class=\"inline\">",$indent);
        ptln("    <thead>",$indent);
        ptln("      <tr><th>".$this->lang["field"]."</th><th>".$this->lang["value"]."</th></tr>",$indent);
        ptln("    </thead>",$indent);
        ptln("    <tbody>",$indent);

        $this->_htmlInputField($cmd."_userid",    "userid",    $this->lang["user_id"],    $user,  $this->_auth->canDo("modLogin"),  true,  $indent+6);
        $this->_htmlInputField($cmd."_userpass",  "userpass",  $this->lang["user_pass"],  "",     $this->_auth->canDo("modPass"),   false, $indent+6);
        $this->_htmlInputField($cmd."_userpass2", "userpass2", $this->lang["passchk"],    "",     $this->_auth->canDo("modPass"),   false, $indent+6);
        $this->_htmlInputField($cmd."_username",  "username",  $this->lang["user_name"],  $name,  $this->_auth->canDo("modName"),   true,  $indent+6);
        $this->_htmlInputField($cmd."_usermail",  "usermail",  $this->lang["user_mail"],  $mail,  $this->_auth->canDo("modMail"),   true,  $indent+6);
        $this->_htmlInputField($cmd."_usergroups","usergroups",$this->lang["user_groups"],$groups,$this->_auth->canDo("modGroups"), false, $indent+6);
        //[START: IOC]
	$this->_htmlInputField($cmd."_usermoodle","usermoodle",$this->lang["user_moodle"],$moodle,$this->_auth->canDo("modMoodle"), true,  $indent+6);
        $this->_htmlSelectField($cmd."_usereditor","usereditor",$this->lang["user_editor"],$editor,$this->_auth->canDo("modEditor"), true,  $indent+6);
	//[END: IOC]
	
        if ($this->_auth->canDo("modPass")) {
            if ($cmd == 'add') {
                $notes[] = $this->lang['note_pass'];
            }
            if ($user) {
                $notes[] = $this->lang['note_notify'];
            }
            ptln("<tr><td><label for=\"".$cmd."_usernotify\" >".$this->lang["user_notify"].": </label></td><td><input type=\"checkbox\" id=\"".$cmd."_usernotify\" name=\"usernotify\" value=\"1\" /></td></tr>", $indent);
        }

        ptln("    </tbody>",$indent);
        ptln("    <tbody>",$indent);
        ptln("      <tr>",$indent);
        ptln("        <td colspan=\"2\">",$indent);
        ptln("          <input type=\"hidden\" name=\"do\"    value=\"admin\" />",$indent);
        ptln("          <input type=\"hidden\" name=\"page\"  value=\"usermanager\" />",$indent);

        // save current $user, we need this to access details if the name is changed
        if ($user) ptln("          <input type=\"hidden\" name=\"userid_old\"  value=\"".hsc($user)."\" />",$indent);

        $this->_htmlFilterSettings($indent+10);

        ptln("          <button type=\"submit\" name=\"fn[".$cmd."]\">".$this->lang[$cmd]."</button>",$indent);
        ptln("        </td>",$indent);
        ptln("      </tr>",$indent);
        ptln("    </tbody>",$indent);
        ptln("  </table>",$indent);

        if ($notes) {
            ptln("    <ul class=\"notes\">");
            foreach ($notes as $note) {
	    	//[START: IOC]
		//ptln("      <li><span class=\"li\">".$note."</li>",$indent);
                ptln("      <li><span class=\"li\">".$note."</span></li>",$indent);
		//[END: IOC]
            }
            ptln("    </ul>");
        }
        ptln("  </div>",$indent);
        ptln("</form>",$indent);
    }

    /**
     * Prints a inputfield
     *
     * @param string $id
     * @param string $name
     * @param string $label
     * @param string $value
     * @param bool   $cando whether auth backend is capable to do this action
     * @param bool   $required is this field required?
     * @param int $indent
     */
    protected function _htmlInputField($id, $name, $label, $value, $cando, $required, $indent=0) {
        $class = $cando ? '' : ' class="disabled"';
        echo str_pad('',$indent);

        if ($name == 'userpass' || $name == 'userpass2'){
            $fieldtype = 'password';
            $autocomp  = 'autocomplete="off"';
        }elseif($name == 'usermail'){
            $fieldtype = 'email';
            $autocomp  = '';
	//[START: IOC]
        }elseif($name == 'usermoodle'){
            $fieldtype = 'checkbox';
            $autocomp  = ($value=="1") ? 'checked' : '';
            $value = "1";
        }elseif($name == 'usereditor'){
            $fieldtype = 'text';
            $autocomp  = '';
            $value = "ACE";
	//[END: IOC]
        }else{
            $fieldtype = 'text';
            $autocomp  = '';
        }
	//[START: IOC]
	//$value = hsc($value);
        if ($value !== "") $value = hsc($value);
	//[END: IOC]
        echo "<tr $class>";
        echo "<td><label for=\"$id\" >$label: </label></td>";
        echo "<td>";
        if($cando){
            $req = ($required) ? 'required="required"' : '';
            echo "<input type=\"$fieldtype\" id=\"$id\" name=\"$name\" value=\"$value\" class=\"edit\" $autocomp $req />";
        }else{
            echo "<input type=\"hidden\" name=\"$name\" value=\"$value\" />";
            echo "<input type=\"$fieldtype\" id=\"$id\" name=\"$name\" value=\"$value\" class=\"edit disabled\" disabled=\"disabled\" />";
        }
        echo "</td>";
        echo "</tr>";
    }

    //[START: IOC]
    /**
     * Prints a Select field
     *
     * @param string $id
     * @param string $name
     * @param string $label
     * @param string $value
     * @param bool   $cando whether auth backend is capable to do this action
     * @param int $indent
     */
    protected function _htmlSelectField($id, $name, $label, $value, $cando, $indent=0) {
        $options = array();
        $class = $disabled = "";
        if (!$cando) {
            $class = 'class="disabled"';
            $disabled = "disabled";
        }
        echo str_pad('', $indent);

        if($name == 'usereditor'){
            $selected = ($value=="ACE") ? " selected" : "";
            $options[] = "<option value=\"ACE\"$selected>ACE</option>";
            $selected = ($value=="DOJO") ? " selected" : "";
            $options[] = "<option value=\"DOJO\"$selected>DOJO</option>";
        }

        echo "<tr $class>";
        echo "<td><label for=\"$id\" >$label: </label></td>";
        echo "<td>";
        if (!$cando) echo "<input type=\"hidden\" name=\"$name\" value=\"$value\" />";
        echo "<select id=\"$id\" name=\"$name\" $class $disabled>";
        foreach ($options as $option) {
            echo $option;
        }
        echo "</td>";
        echo "</tr>";
    }
    //[END: IOC]

    /**
     * Returns htmlescaped filter value
     *
     * @param string $key name of search field
     * @return string html escaped value
     */
    protected function _htmlFilter($key) {
        if (empty($this->_filter)) return '';
        return (isset($this->_filter[$key]) ? hsc($this->_filter[$key]) : '');
    }

    /**
     * Print hidden inputs with the current filter values
     * @param int $indent
     */
    protected function _htmlFilterSettings($indent=0) {

        ptln("<input type=\"hidden\" name=\"start\" value=\"".$this->_start."\" />",$indent);

        foreach ($this->_filter as $key => $filter) {
            ptln("<input type=\"hidden\" name=\"filter[".$key."]\" value=\"".hsc($filter)."\" />",$indent);
        }
    }

    /**
     * Print import form and summary of previous import
     * @param int $indent
     */
    protected function _htmlImportForm($indent=0) {
        global $ID;

        $failure_download_link = wl($ID,array('do'=>'admin','page'=>'usermanager','fn[importfails]'=>1));

        ptln('<div class="level2 import_users">',$indent);
        print $this->locale_xhtml('import');
        ptln('  <form action="'.wl($ID).'" method="post" enctype="multipart/form-data">',$indent);
        formSecurityToken();
        ptln('    <label>'.$this->lang['import_userlistcsv'].'<input type="file" name="import" /></label>',$indent);
        ptln('    <button type="submit" name="fn[import]">'.$this->lang['import'].'</button>',$indent);
        ptln('    <input type="hidden" name="do"    value="admin" />',$indent);
        ptln('    <input type="hidden" name="page"  value="usermanager" />',$indent);

        $this->_htmlFilterSettings($indent+4);
        ptln('  </form>',$indent);
        ptln('</div>');

        // list failures from the previous import
        if ($this->_import_failures) {
            $digits = strlen(count($this->_import_failures));
            ptln('<div class="level3 import_failures">',$indent);
            ptln('  <h3>'.$this->lang['import_header'].'</h3>');
            ptln('  <table class="import_failures">',$indent);
            ptln('    <thead>',$indent);
            ptln('      <tr>',$indent);
	    //[START: IOC]
            //ptln('        <th class="line">'.$this->lang['line'].'</th>',$indent);
            //ptln('        <th class="error">'.$this->lang['error'].'</th>',$indent);
            //ptln('        <th class="userid">'.$this->lang['user_id'].'</th>',$indent);
            //ptln('        <th class="username">'.$this->lang['user_name'].'</th>',$indent);
            //ptln('        <th class="usermail">'.$this->lang['user_mail'].'</th>',$indent);
            //ptln('        <th class="usergroups">'.$this->lang['user_groups'].'</th>',$indent);	    
            ptln('        <th class="line"> | '.$this->lang['line'].'</th>',$indent);
            ptln('        <th class="error"> | '.$this->lang['error'].'</th>',$indent);
            ptln('        <th class="userid"> | '.$this->lang['user_id'].'</th>',$indent);
            ptln('        <th class="username"> | '.$this->lang['user_name'].'</th>',$indent);
            ptln('        <th class="usermail"> | '.$this->lang['user_mail'].'</th>',$indent);
            ptln('        <th class="usermoodle"> | '.$this->lang['user_moodle'].'</th>',$indent);
            ptln('        <th class="usereditor"> | '.$this->lang['user_editor'].'</th>',$indent);
            ptln('        <th class="usergroups"> | '.$this->lang['user_groups'].'</th>',$indent);
	    //[END: IOC]
            ptln('      </tr>',$indent);
            ptln('    </thead>',$indent);
            ptln('    <tbody>',$indent);
            foreach ($this->_import_failures as $line => $failure) {
                ptln('      <tr>',$indent);
                ptln('        <td class="lineno"> | '.sprintf('%0'.$digits.'d',$line).' </td>',$indent);
                ptln('        <td class="error"> | '.$failure['error'].' </td>', $indent);
                ptln('        <td class="field userid"> | '.hsc($failure['user'][0]).' </td>',$indent);
                ptln('        <td class="field username"> | '.hsc($failure['user'][2]).' </td>',$indent);
                ptln('        <td class="field usermail"> | '.hsc($failure['user'][3]).' </td>',$indent);
		//[START: IOC]
                ptln('        <td class="field usermoodle"> | '.hsc($failure['user'][4]).' </td>',$indent);
                ptln('        <td class="field usereditor"> | '.hsc($failure['user'][5]).' </td>',$indent);
		//[END: IOC]
                ptln('        <td class="field usergroups"> | '.hsc($failure['user'][6]).' </td>',$indent);
                ptln('      </tr>',$indent);
            }
            ptln('    </tbody>',$indent);
            ptln('  </table>',$indent);
            ptln('  <p><a href="'.$failure_download_link.'">'.$this->lang['import_downloadfailures'].'</a></p>');
            ptln('</div>');
        }

    }

    /**
     * Add an user to auth backend
     * @return bool whether succesful
     */
    protected function _addUser(){
        global $INPUT;
        if (!checkSecurityToken()) return false;
        if (!$this->_auth->canDo('addUser')) return false;
//[START: IOC]
        //list($user,$pass,$name,$mail,$grps,$passconfirm) = $this->_retrieveUser();
        list($user,$pass,$name,$mail,$moodle,$editor,$grps,/*$delgrps*/ , $passconfirm) = $this->_retrieveUser();
//[END: IOC]
        if (empty($user)) return false;

        if ($this->_auth->canDo('modPass')){
            if (empty($pass)){
	    //[START: IOC]
	        //if($INPUT->has('usernotify')){
                if ($moodle || $INPUT->has('usernotify')){
	    //[END: IOC]
                    $pass = auth_pwgen($user);
                }else {
                    msg($this->lang['add_fail'], -1);
                    msg($this->lang['addUser_error_missing_pass'], -1);
                    return false;
                }
            } else {
                if (!$this->_verifyPassword($pass,$passconfirm)) {
                    msg($this->lang['add_fail'], -1);
                    msg($this->lang['addUser_error_pass_not_identical'], -1);
                    return false;
                }
            }
        } else {
            if (!empty($pass)){
                msg($this->lang['add_fail'], -1);
                msg($this->lang['addUser_error_modPass_disabled'], -1);
                return false;
            }
        }

        if ($this->_auth->canDo('modName')){
            if (empty($name)){
                msg($this->lang['add_fail'], -1);
                msg($this->lang['addUser_error_name_missing'], -1);
                return false;
            }
        } else {
            if (!empty($name)){
                msg($this->lang['add_fail'], -1);
                msg($this->lang['addUser_error_modName_disabled'], -1);
                return false;
            }
        }

        if ($this->_auth->canDo('modMail')){
            if (empty($mail)){
                msg($this->lang['add_fail'], -1);
                msg($this->lang['addUser_error_mail_missing'], -1);
                return false;
            }
        } else {
            if (!empty($mail)){
                msg($this->lang['add_fail'], -1);
                msg($this->lang['addUser_error_modMail_disabled'], -1);
                return false;
            }
        }
        
	//[START: IOC]
        if (empty($moodle)){
            $moodle = '0';
        }

        if (empty($editor)){
            $editor = 'ACE';
        }
	//[END: IOC]
	//[START: IOC]
        //if ($ok = $this->_auth->triggerUserMod('create', array($user,$pass,$name,$mail,$grps))) {
        if (($ok = $this->_auth->triggerUserMod('create', array($user,$pass,$name,$mail,$moodle,$editor,$grps)))) {
        //[END: IOC]
            msg($this->lang['add_ok'], 1);
            if ($INPUT->has('usernotify') && $pass) {
	    //[START: IOC]
	    	//$this->_notifyUser($user,$pass);
                $this->_notifyUser($user, $pass, $moodle);
	    //[END: IOC]
            }
        } else {
            msg($this->lang['add_fail'], -1);
            msg($this->lang['addUser_error_create_event_failed'], -1);
        }

        return $ok;
    }

    /**
     * Delete user from auth backend
     * @return bool whether succesful
     */
    protected function _deleteUser(){
        global $conf, $INPUT;

        if (!checkSecurityToken()) return false;
        if (!$this->_auth->canDo('delUser')) return false;

        $selected = $INPUT->arr('delete');
        if (empty($selected)) return false;
        $selected = array_keys($selected);

        if(in_array($_SERVER['REMOTE_USER'], $selected)) {
            msg("You can't delete yourself!", -1);
            return false;
        }

        $count = $this->_auth->triggerUserMod('delete', array($selected));
        if ($count == count($selected)) {
            $text = str_replace('%d', $count, $this->lang['delete_ok']);
            msg("$text.", 1);
        } else {
            $part1 = str_replace('%d', $count, $this->lang['delete_ok']);
            $part2 = str_replace('%d', (count($selected)-$count), $this->lang['delete_fail']);
            msg("$part1, $part2",-1);
        }

        // invalidate all sessions
        io_saveFile($conf['cachedir'].'/sessionpurge',time());

        return true;
    }

    /**
     * Edit user (a user has been selected for editing)
     *
     * @param string $param id of the user
     * @return bool whether succesful
     */
    protected function _editUser($param) {
        if (!checkSecurityToken()) return false;
        if (!$this->_auth->canDo('UserMod')) return false;
        $user = $this->_auth->cleanUser(preg_replace('/.*[:\/]/','',$param));
        $userdata = $this->_auth->getUserData($user);

        // no user found?
        if (!$userdata) {
            msg($this->lang['edit_usermissing'],-1);
            return false;
        }

        $this->_edit_user = $user;
        $this->_edit_userdata = $userdata;

        return true;
    }

    /**
     * Modify user in the auth backend (modified user data has been recieved)
     * @return bool whether succesful
     */
    protected function _modifyUser(){
        global $conf, $INPUT;

        if (!checkSecurityToken()) return false;
        if (!$this->_auth->canDo('UserMod')) return false;

        // get currently valid  user data
        $olduser = $this->_auth->cleanUser(preg_replace('/.*[:\/]/','',$INPUT->str('userid_old')));
        $oldinfo = $this->_auth->getUserData($olduser);

        // get new user data subject to change
        //[START: IOC]
	//list($newuser,$newpass,$newname,$newmail,$newgrps,$passconfirm) = $this->_retrieveUser();
        list($newuser,$newpass,$newname,$newmail,$newmoodle,$neweditor,$newgrps,$delgrps,$passconfirm) = $this->_retrieveUser();
        //[END: IOC]
        if (empty($newuser)) return false;

        $changes = array();
        if ($newuser != $olduser) {

            if (!$this->_auth->canDo('modLogin')) {        // sanity check, shouldn't be possible
                msg($this->lang['update_fail'],-1);
                return false;
            }

            // check if $newuser already exists
            if ($this->_auth->getUserData($newuser)) {
                msg(sprintf($this->lang['update_exists'],$newuser),-1);
                $re_edit = true;
            } else {
                $changes['user'] = $newuser;
            }
        }
        if ($this->_auth->canDo('modPass')) {
            if ($newpass || $passconfirm) {
                if ($this->_verifyPassword($newpass,$passconfirm)) {
                    $changes['pass'] = $newpass;
                } else {
                    return false;
                }
            } else {
                // no new password supplied, check if we need to generate one (or it stays unchanged)
                if ($INPUT->has('usernotify')) {
                    $changes['pass'] = auth_pwgen($olduser);
                }
            }
        }

        if (!empty($newname) && $this->_auth->canDo('modName') && $newname != $oldinfo['name']) {
            $changes['name'] = $newname;
        }
        if (!empty($newmail) && $this->_auth->canDo('modMail') && $newmail != $oldinfo['mail']) {
            $changes['mail'] = $newmail;
        }
	//[START: IOC]
        if ($this->_auth->canDo('modMoodle') && $newmoodle != $oldinfo['moodle']) {
            $changes['moodle'] = $newmoodle;
        }
        if ($this->_auth->canDo('modEditor') && $neweditor != $oldinfo['editor']) {
            $changes['editor'] = $neweditor;
        }
        //[END: IOC]
        //[START: IOC]
//        if (!empty($newgrps) && $this->_auth->canDo('modGroups') && $newgrps != $oldinfo['grps']) {
//            $changes['grps'] = $newgrps;
//        }
	if ($this->_auth->canDo('modGroups')) {
            $changes['grps'] = (empty($newgrps)) ? ["user"] : $newgrps;
        }
        //[END: IOC]
        //[START: IOC]
        if (!empty($delgrps) && $this->_auth->canDo('modGroups')) {
            $change = FALSE;
            foreach($delgrps as $v){
                $change = $change || in_array($v, $oldinfo['grps']);
            }
            if ($change) $changes['delgrps'] = $delgrps;
        }
        //[END: IOC]
        if (($ok = $this->_auth->triggerUserMod('modify', array($olduser, $changes)))) {
            msg($this->lang['update_ok'],1);

            if ($INPUT->has('usernotify') && !empty($changes['pass'])) {
                $notify = empty($changes['user']) ? $olduser : $newuser;
		//[START: IOC]
                $this->_notifyUser($notify, $changes['pass'], $newmoodle);   //$this->_notifyUser($notify,$changes['pass']);
		//[END: IOC]
            }

            // invalidate all sessions
            io_saveFile($conf['cachedir'].'/sessionpurge',time());

        } else {
            msg($this->lang['update_fail'],-1);
        }

        if (!empty($re_edit)) {
            $this->_editUser($olduser);
        }

        return $ok;
    }

     //[START: IOC]
    /**
     * Send password change notification email
     *
     * @param string $user         id of user
     * @param string $password     plain text
     * @param bool   $moodle       0=contrasenya de la wiki, 1=es valida al moodle
     * @param bool   $status_alert whether status alert should be shown
     * @return bool whether succesful
     */
//     protected function _notifyUser($user, $password, $status_alert=true) {
//    
//        if ($sent = auth_sendPassword($user,$password)) {
//            if ($status_alert) {
//                msg($this->lang['notify_ok'], 1);
//            }
//        } else {
//            if ($status_alert) {
//                msg($this->lang['notify_fail'], -1);
//            }
//        }
//
//        return $sent;
//    }
    protected function _notifyUser($user, $password, $moodle, $status_alert=true) {
        $password = ($moodle===0) ? $password : "Utilitza la contrasenya de moodle";
        if (($sent = auth_sendPassword($user, $password))) {
            if ($status_alert) {
                msg($this->lang['notify_ok'], 1);
            }
        }else if ($status_alert) {
            msg($this->lang['notify_fail'], -1);
        }

        return $sent;
    }
    //[END: IOC]

    /**
     * Verify password meets minimum requirements
     * :TODO: extend to support password strength
     *
     * @param string  $password   candidate string for new password
     * @param string  $confirm    repeated password for confirmation
     * @return bool   true if meets requirements, false otherwise
     */
    protected function _verifyPassword($password, $confirm) {
        global $lang;

        if (empty($password) && empty($confirm)) {
            return false;
        }

        if ($password !== $confirm) {
            msg($lang['regbadpass'], -1);
            return false;
        }

        // :TODO: test password for required strength

        // if we make it this far the password is good
        return true;
    }

    /**
     * Retrieve & clean user data from the form
     *
     * @param bool $clean whether the cleanUser method of the authentication backend is applied
     * @return array (user, password, full name, email, array(groups))
     */
    protected function _retrieveUser($clean=true) {
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;
        global $INPUT;

        $user = array();
        $user[0] = ($clean) ? $auth->cleanUser($INPUT->str('userid')) : $INPUT->str('userid');
        $user[1] = $INPUT->str('userpass');
        $user[2] = $INPUT->str('username');
        $user[3] = $INPUT->str('usermail');
	//[START: IOC]
//        $user[4] = explode(',',$INPUT->str('usergroups'));
//        $user[5] = $INPUT->str('userpass2');                // repeated password for confirmation
//
//        $user[4] = array_map('trim',$user[4]);
//        if($clean) $user[4] = array_map(array($auth,'cleanGroup'),$user[4]);
//        $user[4] = array_filter($user[4]);
//        $user[4] = array_unique($user[4]);
//        if(!count($user[4])) $user[4] = null;	
        $user[4] = $INPUT->str('usermoodle', "0");
        $user[5] = $INPUT->str('usereditor', "ACE");
        $user[6] = explode(',',$INPUT->str('usergroups'));
        $user[7] = explode(',',$INPUT->str('userdeletegroups'));
        $user[8] = $INPUT->str('userpass2');     // repeated password for confirmation

        $user[6] = array_map('trim',$user[6]);
        if($clean) $user[6] = array_map(array($auth,'cleanGroup'),$user[6]);
        $user[6] = array_filter($user[6]);
        $user[6] = (count($user[6])) ? array_values(array_unique($user[6])) : null;

        $user[7] = array_map('trim', $user[7]);
        if ($clean) $user[7] = array_map(array($auth,'cleanGroup'), $user[7]);
        $user[7] = array_filter($user[7]);
        $user[7] = (count($user[7])) ? array_values(array_unique($user[7])) : null;
	//[END: IOC]

        return $user;
    }

    /**
     * Set the filter with the current search terms or clear the filter
     *
     * @param string $op 'new' or 'clear'
     */
    protected function _setFilter($op) {

        $this->_filter = array();

        if ($op == 'new') {
	    //[START: IOC]
	    //list($user,/* $pass */,$name,$mail,$grps) = $this->_retrieveUser(false);
            list($user,/* $pass */,$name,$mail,$moodle,$editor,$grps,/* $delgrps */) = $this->_retrieveUser(false);
	    //[END: IOC]

            if (!empty($user))   $this->_filter['user']   = $user;
            if (!empty($name))   $this->_filter['name']   = $name;
            if (!empty($mail))   $this->_filter['mail']   = $mail;
	    //[START: IOC]
            if (!empty($moodle)) $this->_filter['moodle'] = ($moodle=="on") ? 1 : 0;
            if (!empty($editor)) $this->_filter['editor'] = $editor;
	    //[END: IOC]
            if (!empty($grps))   $this->_filter['grps']   = join('|',$grps);
        }
    }

    /**
     * Get the current search terms
     * @return array
     */
    protected function _retrieveFilter() {
        global $INPUT;

        $t_filter = $INPUT->arr('filter');

        // messy, but this way we ensure we aren't getting any additional crap from malicious users
        $filter = array();

        if (isset($t_filter['user']))   $filter['user']   = $t_filter['user'];
        if (isset($t_filter['name']))   $filter['name']   = $t_filter['name'];
        if (isset($t_filter['mail']))   $filter['mail']   = $t_filter['mail'];
	//[START: IOC]
        if (isset($t_filter['moodle'])) $filter['moodle'] = $t_filter['moodle'];
        if (isset($t_filter['editor'])) $filter['editor'] = $t_filter['editor'];
	//[END: IOC]
        if (isset($t_filter['grps']))   $filter['grps']   = $t_filter['grps'];

        return $filter;
    }

    /**
     * Validate and improve the pagination values
     */
    protected function _validatePagination() {

        if ($this->_start >= $this->_user_total) {
            $this->_start = $this->_user_total - $this->_pagesize;
        }
        if ($this->_start < 0) $this->_start = 0;

        $this->_last = min($this->_user_total, $this->_start + $this->_pagesize);
    }

    /**
     * Return an array of strings to enable/disable pagination buttons
     *
     * @return array with enable/disable attributes
     */
    protected function _pagination() {

        $disabled = 'disabled="disabled"';

        $buttons = array();
        $buttons['start'] = $buttons['prev'] = ($this->_start == 0) ? $disabled : '';

        if ($this->_user_total == -1) {
            $buttons['last'] = $disabled;
            $buttons['next'] = '';
        } else {
            $buttons['last'] = $buttons['next'] = (($this->_start + $this->_pagesize) >= $this->_user_total) ? $disabled : '';
        }

        if ($this->_lastdisabled) {
            $buttons['last'] = $disabled;
        }

        return $buttons;
    }

    /**
     * Export a list of users in csv format using the current filter criteria
     */
    protected function _export() {
        // list of users for export - based on current filter criteria
        $user_list = $this->_auth->retrieveUsers(0, 0, $this->_filter);
        $column_headings = array(
            $this->lang["user_id"],
            $this->lang["user_name"],
            $this->lang["user_mail"],
	    //[START: IOC]
            $this->lang["user_moodle"],
            $this->lang["user_editor"],
	    //[END: IOC]
            $this->lang["user_groups"]
        );

        // =================================
        // GENERATE OUTPUT
        // normal headers for downloading...
        header('Content-type: text/csv;charset=utf-8');
        header('Content-Disposition: attachment; filename="wikiusers.csv"');
#       // for debugging assistance, send as text plain to the browser
#       header('Content-type: text/plain;charset=utf-8');

        // output the csv
        $fd = fopen('php://output','w');
        fputcsv($fd, $column_headings);
        foreach ($user_list as $user => $info) {
	    //[START: IOC]
	    //$line = array($user, $info['name'], $info['mail'], join(',',$info['grps']));
            $line = array($user, $info['name'], $info['mail'], $info['moodle'], $info['editor'], join(',',$info['grps']));
	    //[END: IOC]
            fputcsv($fd, $line);
        }
        fclose($fd);
        if (defined('DOKU_UNITTEST')){ return; }

        die;
    }

    /**
     * Import a file of users in csv format
     //[START: IOC]
     * csv file should have 6 columns, user_id, full name, email, moodle, editor, groups, (optional) deletegroups (comma separated)
     //[END: IOC]
     *
     * @return bool whether successful
     */
    protected function _import() {
        // check we are allowed to add users
        if (!checkSecurityToken()) return false;
        if (!$this->_auth->canDo('addUser')) return false;

        // check file uploaded ok.
        if (empty($_FILES['import']['size']) || !empty($_FILES['import']['error']) && $this->_isUploadedFile($_FILES['import']['tmp_name'])) {
            msg($this->lang['import_error_upload'],-1);
            return false;
        }
        // retrieve users from the file
        $this->_import_failures = array();
        $import_success_count = 0;
        $import_fail_count = 0;
        $line = 0;
        $fd = fopen($_FILES['import']['tmp_name'],'r');
        if ($fd) {
            while($csv = fgets($fd)){
                //[START: IOC]
//                if (!utf8_check($csv)) {
//                    $csv = utf8_encode($csv);
//                }
//                $raw = str_getcsv($csv);
//                $error = '';                        // clean out any errors from the previous line
//                // data checks...
			    
                if (empty(trim($csv))) continue; //ignora líneas vacías
                if (!utf8_check($csv)) $csv = utf8_encode($csv);
                $raw = str_getcsv($csv);        //convierte la fila string delimitado en un array
                $error = '';                    // clean out any errors from the previous line
		//[END: IOC]

                if (1 == ++$line) {
                    if ($raw[0] == 'user_id' || $raw[0] == $this->lang['user_id']) continue;    // skip headers
                }
		//[START: IOC]
                if (count($raw) < 5) {  // need at least 5 fields      (original: if (count($raw) < 4) {                                        // need at least four fields)
		//[END: IOC]
                    $import_fail_count++;
                    $error = sprintf($this->lang['import_error_fields'], count($raw));
                    $this->_import_failures[$line] = array('error' => $error, 'user' => $raw, 'orig' => $csv);
                    continue;
                }
		//[START: IOC]
//		array_splice($raw,1,0,auth_pwgen());                          // splice in a generated password
//                $clean = $this->_cleanImportUser($raw, $error);
//                if ($clean && $this->_addImportUser($clean, $error)) {
//                    $sent = $this->_notifyUser($clean[0],$clean[1],false);
//                    if (!$sent){
//                        msg(sprintf($this->lang['import_notify_fail'],$clean[0],$clean[3]),-1);
//                    }
//                    $import_success_count++;
//                } else {
//                    $import_fail_count++;
//                    array_splice($raw, 1, 1);                                  // remove the spliced in password
//                    $this->_import_failures[$line] = array('error' => $error, 'user' => $raw, 'orig' => $csv);
//                }
		
                //Verificamos si el usuario ya existe
                $userData = $this->_auth->getUserData($raw[0]);
                if ($userData) {
                    $clean = $this->_cleanImportModifyUser($raw, $error);
                    if ($clean && $this->_modifyImportUser($clean, $error)) {
                        $import_success_count++;
                        $error = "usuari modificat";
                    } else {
                        $import_fail_count++;
                    }
                    array_splice($raw,1,0,"."); //añade un 2º elemento en el array para simular el password
                    $this->_import_failures[$line] = array('error'=>$error, 'user'=>$raw, 'orig'=>$csv);
                }else {
                    if (!$clean[4]) {  //valor 0, '', null: usuario no se valida en moodle
                        array_splice($raw,1,0,auth_pwgen());  //inserta un password generado automáticamente como 2º elemento del array
                    }
                    $clean = $this->_cleanImportUser($raw, $error);
                    if ($clean && $this->_addImportUser($clean, $error)) {
                        $sent = $this->_notifyUser($clean[0], $clean[1], $clean[4], false);
                        if (!$sent){
                            msg(sprintf($this->lang['import_notify_fail'],$clean[0],$clean[3]),-1);
                        }
                        $import_success_count++;
                        $error = "nou usuari";
                    } else {
                        $import_fail_count++;
                        if (!$clean[4]) {
                            array_splice($raw, 1, 1); // remove the spliced in password
                        }
                    }
                    $this->_import_failures[$line] = array('error'=>$error, 'user'=>$raw, 'orig'=>$csv);
                }
		//[END: IOC]
            }
            msg(sprintf($this->lang['import_success_count'], ($import_success_count+$import_fail_count), $import_success_count),($import_success_count ? 1 : -1));
            if ($import_fail_count) {
                msg(sprintf($this->lang['import_failure_count'], $import_fail_count),-1);
            }
        } else {
            msg($this->lang['import_error_readfail'],-1);
        }

        // save import failures into the session
        if (!headers_sent()) {
            session_start();
            $_SESSION['import_failures'] = $this->_import_failures;
            session_write_close();
        }
        return true;
    }

    /**
     * Returns cleaned user data
     * @param array $candidate raw values of line from input file
     * @param string $error
     * @return array|false cleaned data or false
     */
    protected function _cleanImportUser($candidate, & $error){
        global $INPUT;

        // kludgy ....
        $INPUT->set('userid', $candidate[0]);
        $INPUT->set('userpass', $candidate[1]);
        $INPUT->set('username', $candidate[2]);
        $INPUT->set('usermail', $candidate[3]);
	//[START: IOC]
        $INPUT->set('usermoodle', $candidate[4]);
        $INPUT->set('usereditor', $candidate[5]);
	//$INPUT->set('usergroups', $candidate[4]);
        $INPUT->set('usergroups', $candidate[6]);
        $INPUT->set('userdeletegroups', $candidate[7]);

        $cleaned = $this->_retrieveUser();
	//list($user,/* $pass */,$name,$mail,/* $grps */) = $cleaned;
        list($user,/* $pass */,$name,$mail,/* $moodle */,/* $editor */,/* $grps */,/* $delgrps */,/* $verifypass */) = $cleaned;
	//[END: IOC]

        if (empty($user)) {
            $error = $this->lang['import_error_baduserid'];
            return false;
        }

        // no need to check password, handled elsewhere

        if (!($this->_auth->canDo('modName') xor empty($name))){
            $error = $this->lang['import_error_badname'];
            return false;
        }

        if ($this->_auth->canDo('modMail')) {
            if (empty($mail) || !mail_isvalid($mail)) {
                $error = $this->lang['import_error_badmail'];
                return false;
            }
        } else {
            if (!empty($mail)) {
                $error = $this->lang['import_error_badmail'];
                return false;
            }
        }

        return $cleaned;
    }

    //[START: IOC]
    /**
     * Returns cleaned user data for modify
     * @param array $candidate raw values of line from input file
     * @param $error
     * @return array|bool cleaned data or false
     */
    protected function _cleanImportModifyUser($candidate, & $error){
        global $INPUT;
        $INPUT->set('userid', $candidate[0]);
        $INPUT->set('userpass', "");
        $INPUT->set('username', $candidate[1]);
        $INPUT->set('usermail', $candidate[2]);
        $INPUT->set('usermoodle', $candidate[3]);
        $INPUT->set('usereditor', $candidate[4]);
        $INPUT->set('usergroups', $candidate[5]);
        if (!empty($candidate[6]))
            $INPUT->set('userdeletegroups', $candidate[6]);

        $cleaned = $this->_retrieveUser();

        if (empty($cleaned[0])) {
            $error = $this->lang['import_error_baduserid'];
            return false;
        }
        return $cleaned;
    }
    //[END: IOC]

    /**
     * Adds imported user to auth backend
     * Required a check of canDo('addUser') before
     *
     * @param array  $user   data of user
     * @param string &$error reference catched error message
     * @return bool whether successful
     */
    protected function _addImportUser($user, & $error){
        if (!$this->_auth->triggerUserMod('create', $user)) {
            $error = $this->lang['import_error_create'];
            return false;
        }

        return true;
    }

    //[START: IOC]
    /**
     * Modify imported user to auth backend
     * Required a check of canDo('addUser') before
     * @param array  $user   data of user
     * @param string &$error reference catched error message
     * @return bool whether successful
     */
    protected function _modifyImportUser($user, & $error){
        $params[0] = $user[0];
        //$params[1]['pass'] = $user[1]; NO TOCAR: Se oculta el passwd dado que no se permite cambiarlo aquí
        $params[1]['name']   = $user[2];
        $params[1]['mail']   = $user[3];
        $params[1]['moodle'] = $user[4];
        $params[1]['editor'] = $user[5];
        $params[1]['grps']   = $user[6];
        $params[1]['delgrps']= $user[7];
        $params[1]['ignoreNull'] = TRUE;    //No se actualizarán los valores vacíos
        $params[1]['onlyAddGroup'] = TRUE;  //Sólo se añadirán grupos nuevos, no se eliminarán grupos ya existentes
        if (!$this->_auth->triggerUserMod('modify', $params)) {
            $error = $this->lang['import_error_modify'] || "import_error_modify";
            return false;
        }
        return true;
    }
    //[END: IOC]

    /**
     * Downloads failures as csv file
     */
    protected function _downloadImportFailures(){
        // =================================
        // GENERATE OUTPUT
        // normal headers for downloading...
        header('Content-type: text/csv;charset=utf-8');
        header('Content-Disposition: attachment; filename="importfails.csv"');
#       // for debugging assistance, send as text plain to the browser
#       header('Content-type: text/plain;charset=utf-8');

        // output the csv
        $fd = fopen('php://output','w');
        foreach ($this->_import_failures as $fail) {
            fputs($fd, $fail['orig']);
        }
        fclose($fd);
        die;
    }

    /**
     * wrapper for is_uploaded_file to facilitate overriding by test suite
     * @param string $file filename
     * @return bool
     */
    protected function _isUploadedFile($file) {
        return is_uploaded_file($file);
    }
}
