# niix2-audit

Extensions to the Yii 2 PHP framework allowing tracking and viewing change history of a model.

Provides:

* a controller action with a view to view model change history
* a model behavior with a method that loads older model versions
* a command to manage and verify audit database objects

# Architecture

* a library with an interface for a schema generator, basically a set of sql templates
* a library to process existing db structures and generate migrations, that is sql scripts
* make a framework specific (behaviors) layer to support more features

# References

* https://github.com/airblade/paper_trail
* http://en.wikipedia.org/wiki/Slowly_changing_dimension
