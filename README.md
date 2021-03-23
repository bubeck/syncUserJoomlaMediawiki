# syncUserJoomlaMediawiki

Transfer Joomla user accounts into mediawiki. This script takes all
accounts and password from a Joomla installation and creates the
identical users (and passwords) on the mediawiki side.  This can be
used to allow Joomla users to use a mediawiki with identical
credentials.

A typical usage would be to call the script with

    shell% php syncUserJoomlaMediawiki.php -j /var/www/html/joomla -m /var/www/html/wiki
    
This will take all users from joomla and transfer them into
mediawiki. This could be done once or regularly (e.g. once a day to
keep mediawiki updated).

## Dry-Run and verbose

The command line argument "-v" turns on verbosity, where everything
done by the script is printed to stdout. This helps understanding and
debugging problems.

By using the command line arguments "-k" you can do a dry run. In this
mode nothing will be changed. You typically use this together with
"-v" to see, what would have been done.

## How does it work?

It connects to the database of joomla by using the credential found in
Joomla's configuration.php. The same is done with the database of
mediawiki and its LocalSettings.php. Then all users of Joomla and
mediawiki are compared. If they are missing in mediawiki then
maintenance/createAndPromote.php is used to create the user.

### Transfer joomla password into mediawiki password.

Joomla always uses bcrypt hash function inside PHP's password_hash to
generate the hashed password.  Mediawiki is able to handle multiple
hash functions and therefore needs to know, which hash function is
used for the given hash value. This is done with the ":algo:"
prefix. By using ":bcrypt:" mediawiki will use also use bcrypt to hash
the value. This means, that we can transfer the hash value without
knowing the real password.

Another (small) difference is, that Joomla concatenates salt and hash
without a delimiter and mediawiki uses "$" as a delimiter between salt
and hash.


