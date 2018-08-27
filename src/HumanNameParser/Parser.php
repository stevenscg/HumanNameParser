<?php

/**
 * Split a single name string into it's name parts (first name, last name, titles, middle names)
 */

namespace HumanNameParser;

use HumanNameParser\Name;
use HumanNameParser\Exception\FirstNameNotFoundException;
use HumanNameParser\Exception\LastNameNotFoundException;
use HumanNameParser\Exception\NameParsingException;

class Parser {

    // The regex use is a bit tricky.  *Everything* matched by the regex will be replaced,
    //    but you can select a particular parenthesized submatch to be returned.
    //    Also, note that each regex requires that the preceding ones have been run, and matches chopped out.
    CONST REGEX_NICKNAMES       =  "/ ('|\"|\(\"*'*)(.+?)('|\"|\"*'*\)) /i"; // names that starts or end w/ an apostrophe break this
    CONST REGEX_TITLES          =  "/(%s)\s+/i";
    CONST REGEX_SUFFIX          =  "/(\*,) *(%s)$/i";
    CONST REGEX_LAST_NAME       =  "/(?!^)\b([^ ]+ y |%s)*[^ ]+$/i";
    CONST REGEX_LEADING_INITIAL =  "/^(.\.*)(?= \p{L}{2})/i"; // note the lookahead, which isn't returned or replaced
    CONST REGEX_FIRST_NAME      =  "/^[^ ]+/i"; //

    /**
     * @var array
     */
    private $suffixes = array();

    /**
     * @var array
     */
    private $prefixes = array();

    /**
     * @var array
     */
    private $academicTitles = array();

    /**
     * @var string
     */
    private $nameToken = null;

    /**
     * @var boolean
     */
    private $mandatoryFirstName = true;

    /**
     * @var boolean
     */
    private $mandatoryLastName = true;


     /*
      * Constructor
      *
      * @param array of options
      *                 'suffixes' for an array of suffixes
      *                 'prefix' for an array of prefixes
      */
    public function __construct($options = array())
    {
        if (!isset($options['suffixes']))
        {
            $options['suffixes'] = array('esq','esquire','jr','sr','2','ii','iii','iv','\(child\)','child');
        }
        if (!isset($options['prefixes']))
        {
            $options['prefixes'] =  array('bar','ben','bin','da','dal','de la', 'de', 'del','der','di',
                          'ibn','la','le','san','st','ste','van', 'van der', 'van den', 'vel','von');
        }
        if (!isset($options['academic_titles']))
        {
            $options['academic_titles'] =  array('ms','miss','mstr','mrs','mr','prof','dr');
        }
        if (isset($options['mandatory_first_name'])) {
            $this->mandatoryFirstName = (boolean) $options['mandatory_first_name'];
        }
        if (isset($options['mandatory_last_name'])) {
            $this->mandatoryLastName = (boolean) $options['mandatory_last_name'];
        }

        $this->setSuffixes($options['suffixes']);
        $this->setPrefixes($options['prefixes']);
        $this->setAcademicTitles($options['academic_titles']);
    }


    /**
     * Parse the name into its constituent parts.
     *
     *
     * @return Name the parsed name
     */
    public function parse($name)
    {
        $suffixes = implode("\.*|", $this->suffixes) . "\.*"; // each suffix gets a "\.*" behind it.
        $prefixes = implode(" |", $this->prefixes) . " "; // each prefix gets a " " behind it.
        $academicTitles = implode("\.*|", $this->academicTitles) . "\.*"; // each suffix gets a "\.*" behind it.

        $this->nameToken = $name;
        $this->name = new Name();

        // Flip on slashes before any other transformations.
        $this->flipNameToken('/', [
            [ 'limit' => 2, 'order' => [2,1,0] ], // Last / First / Title => Title First Last
            [ 'limit' => 1, 'order' => [1,0]   ], // Last / First+ => First+ Last
        ]);

        $this->findAcademicTitle($academicTitles);
        $this->findNicknames();

        $this->findSuffix($suffixes);

        // Flip on commas.
        $this->flipNameToken(',', [
            [ 'limit' => 2, 'order' => [1,2,0] ], // Last, First, Middle => First Middle Last
            [ 'limit' => 1, 'order' => [1,0]   ], // Last, First+ => First+ Last
        ]);

        $this->findLastName($prefixes);
        $this->findLeadingInitial();
        $this->findFirstName();
        $this->findMiddleName();

        return $this->name;
    }


    /**
     * @param  string $academicTitles
     *
     * @return Parser
     */
    private function findAcademicTitle($academicTitles)
    {
        $regex = sprintf(self::REGEX_TITLES, $academicTitles);
        $title = $this->findWithRegex($regex, 1);
        if ($title) {
            $this->name->setAcademicTitle($title);
            $this->removeTokenWithRegex($regex, false);
        }

        return $this;
    }


    /**
     * @return Parser
     */
    private function findNicknames()
    {
        $nicknames = $this->findWithRegex(self::REGEX_NICKNAMES, 2);
        if ($nicknames) {
            $this->name->setNicknames($nicknames);
            $this->removeTokenWithRegex(self::REGEX_NICKNAMES);
        }

        return $this;
    }


    /**
     * @param  string $suffixes
     *
     * @return Parser
     */
    private function findSuffix($suffixes)
    {
        $regex = "/,* *($suffixes)$/i";
        $suffix = $this->findWithRegex($regex, 1);
        if ($suffix) {
            $this->name->setSuffix($suffix);
            $this->removeTokenWithRegex($regex);
        }

        return $this;
    }


    /**
     * @return Parser
     */
    private function findLastName($prefixes)
    {
        $regex = sprintf(self::REGEX_LAST_NAME, $prefixes);
        $lastName = $this->findWithRegex($regex, 0);
        if ($lastName) {
            $this->name->setLastName($lastName);
            $this->removeTokenWithRegex($regex);
        } elseif ($this->mandatoryLastName) {
            throw new LastNameNotFoundException("Couldn't find a last name.");
        }

        return $this;
    }


    /**
     * @return Parser
     */
    private function findFirstName()
    {
        $firstName = $this->findWithRegex(self::REGEX_FIRST_NAME, 0);
        if ($firstName) {
            $this->name->setFirstName($firstName);
            $this->removeTokenWithRegex(self::REGEX_FIRST_NAME);
        } elseif ($this->mandatoryFirstName) {
            throw new FirstNameNotFoundException("Couldn't find a first name.");
        }

        return $this;
    }


    /**
     * @return Parser
     */
    private function findLeadingInitial()
    {
        $leadingInitial = $this->findWithRegex(self::REGEX_LEADING_INITIAL, 1);
        if ($leadingInitial) {
            $this->name->setLeadingInitial($leadingInitial);
            $this->removeTokenWithRegex(self::REGEX_LEADING_INITIAL);
        }

        return $this;
    }


    /**
     * @return Parser
     */
    private function findMiddleName()
    {
        $middleName = trim($this->nameToken);
        if ($middleName) {
            $this->name->setMiddleName($middleName);
        }

        return $this;
    }


    /**
     * @return string
     */
    private function findWithRegex($regex, $submatchIndex = 0)
    {
        $regex = $regex . "ui"; // unicode + case-insensitive
        preg_match($regex, $this->nameToken, $m);
        $subset = (isset($m[$submatchIndex])) ? $m[$submatchIndex] : false;

        return $subset;
    }


    /**
     * @return void
     */
    private function removeTokenWithRegex($regex, $normalize = true)
    {
        $numReplacements = 0;
        $tokenRemoved = preg_replace($regex, ' ', $this->nameToken, -1, $numReplacements);
        if ($numReplacements > 1) {
            throw new NameParsingException("The regex being used has multiple matches.");
        }

        if (!$normalize) {
            $this->nameToken = $tokenRemoved;
            return;
        }

        $this->nameToken = $this->normalize($tokenRemoved);
    }


    /**
     * Removes extra whitespace and punctuation from string
     * Strips whitespace chars from ends, strips redundant whitespace, converts whitespace chars to " ".
     *
     * @param string $taintedString
     *
     * @return string
    */
    private function normalize($taintedString)
    {
         $taintedString = preg_replace( "#^\s*#u", "", $taintedString );
         $taintedString = preg_replace( "#\s*$#u", "", $taintedString );
         $taintedString = preg_replace( "#\s+#u", " ", $taintedString );
         $taintedString = preg_replace( "#,$#u",  " ", $taintedString );

         return $taintedString;
    }


    /**
     * @return Parser
     * @param string $pattern
     */
    private function flipNameToken($char = ",", $patterns = [])
    {
        $this->nameToken = $this->flipStringPartsAround($this->nameToken, $char, $patterns);

        return $this;
    }


    /**
     * Flips the front and back parts of a name with one another.
     * Front and back are determined by a specified character somewhere in the
     * middle of the string.
     *
     * @param  string $string The name string to flip.
     * @param  string $char The character(s) demarcating the parts to flip.
     * @param  array  $patterns An array of flip definitions.
     *
     * @return string
     */
    private function flipStringPartsAround($string, $char, $patterns = [])
    {
        foreach ($patterns as $item) {
            // Automatically escape regex control characters.
            $escapedChar = in_array($char, ['/']) ? "\\$char" : $char;

            $substrings = preg_split("/$escapedChar/u", $string);
            if ((count($substrings) - 1) !== $item['limit']) {
                continue;
            }

            $pieces = [];
            foreach ($item['order'] as $idx) {
                $pieces[] = $substrings[$idx];
            }

            // Return the re-ordered name string.
            return $this->normalize(implode(' ', $pieces));
        }

        // Return the original name string unchanged.
        return $string;
    }


    /**
     * Gets the value of suffixes.
     *
     * @return array
     */
    public function getSuffixes()
    {
        return $this->suffixes;
    }


    /**
     * Sets the value of suffixes.
     *
     * @param array $suffixes the suffixes
     *
     * @return self
     */
    public function setSuffixes(array $suffixes)
    {
        $this->suffixes = $suffixes;

        return $this;
    }


    /**
     * Gets the value of prefixes.
     *
     * @return array
     */
    public function getPrefixes()
    {
        return $this->prefixes;
    }


    /**
     * Sets the value of prefixes.
     *
     * @param array $prefixes the prefixes
     *
     * @return self
     */
    public function setPrefixes(array $prefixes)
    {
        $this->prefixes = $prefixes;

        return $this;
    }


    /**
     * Gets the value of academicTitles.
     *
     * @return array
     */
    public function getAcademicTitles()
    {
        return $this->academicTitles;
    }


    /**
     * Sets the value of academicTitles.
     *
     * @param array $academicTitles the academic titles
     *
     * @return self
     */
    public function setAcademicTitles(array $academicTitles)
    {
        $this->academicTitles = $academicTitles;

        return $this;
    }
}
