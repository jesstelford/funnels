Funnels
=======
A Piwik (http://piwik.org/) plugin that allows the definition, analysis and visualisation of funnels associated with goals.

Credits
=======
German translation by Uwe Schulz
Updated to work with Piwik v1.5 by Jess Telford @ Zibbet.com

Changelog
=========

Funnels 1.11.1
--------------
* Fixed bug to use funnels on Piwik 1.11.1

Funnels 20062011
----------------
* Funnels can be defined by page title as well as URL
* A match doesn't have to be exact, it can now be; exact, 'contains', or a regular expression (the same as for Goals)
* A match can be case sensitive or not
* A manually triggered goal will now be correctly tracked as a funnel conversion for known visitors
* Can trigger a goal conversion by URL, such as: http://piwik.example.com/piwik.php?token_auth=[Super_User_Auth_Token]&cid=[HEX_Id_of_Visitor_to_log_goal_hit_for]&idsite=[site_containing_goal]&idgoal=[id_of_goal]

Funnels 0.2 - 20/10/10
----------------------
* Updated example URL in funnel creation/edit to be absolute
* Fixed primary key on log_funnel_step table - missing column idfunnel was preventing one action from being recorded as a step in more than one funnel. To fix manually, run the following against your database:
	ALTER TABLE [piwik_table_prefix]_log_funnel_step DROP PRIMARY KEY, ADD PRIMARY KEY(`idvisit`, `idfunnel`, `idstep`);

Funnels 0.1  
-----------
Initial release
