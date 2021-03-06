<?php
////////////////////////////////////////////////////////////////////////////
// DATABASE CONNECTION
//
// @author	Ben Russell (benrr101@csh.rit.edu)
//
// @file	inc/databaseConn.php
// @descrip	Provides mysql database connection for the system.
////////////////////////////////////////////////////////////////////////////

// Bring in the config data
require_once dirname(__FILE__) . "/config.php";

// Make a connection to the database
global $DATABASE_SERVER, $DATABASE_USER, $DATABASE_PASS, $DATABASE_DB;
$dbConn = mysql_connect($DATABASE_SERVER, $DATABASE_USER, $DATABASE_PASS);
mysql_select_db($DATABASE_DB, $dbConn);

// Error check
if(!$dbConn) {
	die("Could not connect to database: " . mysql_error());
}

////////////////////////////////////////////////////////////////////////////
// FUNCTIONS

/**
 * Retreives a course specified by very specific descriptors. The resulting
 * array will contain all the information needed for the course: title,
 * instructor, enrollment, times[building, room, day, start, end].
 * @param	int		$quarter	The quarter that the course is in
 * @param	int		$deptNum	The department the course is in
 * @param	int		$courseNum	The course number
 * @param	int		$sectNum	The section number of the course
 * @throws	Exception			Thrown if a database error occurs, the course
 *								could not reliably be determined, or the course
 *								does not exist "type:msg"
 * @returns	array				Course formatted into array as described above
 */
function getCourse($quarter, $deptNum, $courseNum, $sectNum) {
	// Build the query
	$query = "SELECT s.id,";
	$query .= " (CASE WHEN (s.title IS NOT NULL) THEN s.title ELSE c.title END) AS title,";
	$query .= " s.instructor, s.curenroll, s.maxenroll, s.type";
	$query .= " FROM courses AS c, sections AS s";
	$query .= " WHERE c.id = s.course AND c.quarter = {$quarter} AND c.department = {$deptNum} AND c.course = {$courseNum} AND s.section = {$sectNum}";

	// Execute the query and error check
	$result = mysql_query($query);
	if(!$result) {
		throw new Exception("mysql:" . mysql_error());
	} elseif(mysql_num_rows($result) > 1) {
		throw new Exception("ambiguous:{$quarter}-{$deptNum}-{$courseNum}-{$sectNum}");
	} elseif(mysql_num_rows($result) == 0) {
		throw new Exception("objnotfound:{$quarter}-{$deptNum}-{$courseNum}-{$sectNum}");
	}

	// Store the course information
	$row = mysql_fetch_assoc($result);
	$course = array(
		"title"      => $row['title'],
		"instructor" => $row['instructor'],
		"curenroll"  => $row['curenroll'],
		"maxenroll"  => $row['maxenroll'],
		"courseNum"  => "{$deptNum}-{$courseNum}-{$sectNum}",
		"sectionId"  => $row['id'],
		"online"     => $row['type'] == "O"
		);
	
	// If the course is online, then don't even bother looking for it's times
	if($course['online']) { return $course; }

	// Now we query for the times of the section	
	$query = "SELECT * FROM times WHERE section = {$row['id']}";
	$result = mysql_query($query);
	if(!$result) {
		throw new Exception("mysql:" . mysql_error());
	}
	while($row = mysql_fetch_assoc($result)) {
		$course["times"][] = array(
			"bldg"  => $row['building'],
			"room"  => $row['room'],
			"day"   => $row['day'],
			"start" => $row['start'],
			"end"   => $row['end']
			);
	}
	
	// Return the course
	return $course;
}

/**
 * Does a query for all the quarters in the database and then dumps them to
 * a handy drop down field. Parses them like 'Spring ####' for display val.
 * The option value will be the 5 digit number
 * @param	string	$fieldname	The name of the field (useful for multiple 
 *								quarter fields in a single form)
 * @param	string	$selected	The selected value to add to the field
 * @return	string	A dropdown field as described
 */
function getQuarterField($fieldname = "quarter", $selected = null) {
	// Build the start of the field
	$return = "<select id='{$fieldname}' name='{$fieldname}'>";
	
	// Query the database for the quarters
	$query = "SELECT quarter FROM quarters ORDER BY quarter";
	$result = mysql_query($query);
	
	// Output the quarters as options
	while($row = mysql_fetch_assoc($result)) {
		$quarter = $row['quarter'];

		// Parse it into a year-quarter thingy
		$year = substr(strval($quarter), 0, 4);
		$quarternum = substr(strval($quarter), -1);
		switch($quarternum) {
			case 1:
				$quarterName = "Fall";
				break;
			case 2:
				$quarterName = "Winter";
				break;
			case 3:
				$quarterName = "Spring";
				break;
			case 4:
				$quarterName = "Summer";
				break;
			default:
				$quarterName = "Unknown";
				break;
		}

		// Now output it
		$return .= "<option value='{$quarter}'" . (($selected == $quarter) ? " selected='selected'" : "") . ">{$year} {$quarterName}</option>";
	}

	// Close it up and return it
	$return .= "</select>";
	return $return;
}

function getCollegeField($fieldname = "school", $selected = null, $any = false) {
	$return = "<select id='{$fieldname}' name='{$fieldname}'>";
	$return .= ($any) ? "<option value='any'>Any College</option>" : "";
	
	// Query for the schools
	$query = "SELECT * FROM schools ORDER BY id";
	$result = mysql_query($query);

	// Output the schools as options
	while($row = mysql_fetch_assoc($result)) {
		$return .= "<option value='{$row['id']}'" . (($selected == $row['id']) ? " selected='selected'" : "") . ">{$row['id']} {$row['title']}</option>";
	}

	// Close it up and return it
	$return .= "</select>";
	return $return;
}

function getDepartmentField($fieldname = "department", $selected = null, $any = false) {
	$return = "<select id='{$fieldname}' name='{$fieldname}'>";
	$return .= ($any) ? "<option value='any'>Any Department</option>" : "";
	
	// Query the database for the departments
	$query = "SELECT * FROM departments ORDER BY id";
	$result = mysql_query($query);
	
	// Output the departments as options
	while($row = mysql_fetch_assoc($result)) {
		$deptNum = $row['id'];
		$deptTitle = $row['title'];
		$return .= "<option value='{$deptNum}'" . (($selected == $deptNum) ? " selected='selected'" : "") . ">{$deptNum} {$deptTitle}</option>";
	}

	// Close it up and return it
	$return .= "</select>";
	return $return;
}

/**
 * Recursively sanitizes all the information passed to it
 * @param	mixed	$item	The item to sanitize, can be an array
 * @return	mixed	The item after it has been sanitized
 */
function sanitize($item) {
	if(is_array($item)) {
		// If it's an array, then recursively call it on the item
		foreach($item as $key => $value) {
			$item[$key] = sanitize($value);
		}
		return $item;
	} else {
		// Base case, return the sanitized item
		return mysql_real_escape_string($item);
	}
}
