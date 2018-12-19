Fork from HumanNameParser.php originally by Jason Priem <jason@jasonpriem.com>

# Description
Takes human names of arbitrary complexity and various wacky formats like:

* J. Walter Weatherman
* de la Cruz, Ana M.
* James C. ('Jimmy') O'Dell, Jr.
* Dr. James C. ('Jimmy') O'Dell, Jr.

and parses out the:

- leading initial (Like "J." in "J. Walter Weatherman")
- first name (or first initial in a name like 'R. Crumb')
- nicknames (like "Jimmy" in "James C. ('Jimmy') O'Dell, Jr.")
- middle names
- last name (including compound ones like "van der Sar' and "Ortega y Gasset"), and
- suffix (like 'Jr.', 'III')
- title (like 'Dr.', 'Prof') *new*


# How to use

```php
use HumanNameParser\Parser;

$nameparser = new Parser();
$name = $nameparser->parse("Alfonso Ribeiro");

echo "Hello " . $name->getFirstName();
```
