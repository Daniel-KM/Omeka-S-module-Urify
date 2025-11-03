Urify (module for Omeka S)
==========================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Urify] is a module for [Omeka S] that allows to check and update references to
the external list of authorities managed by the module [Value Suggest], for
example [IdRef], in particular for authors and subjects.

It does a search and list all the matching possibilities, allowing to replace an
existing uri or to choose a new one for each literal value.

The module [Bulk Edit] does the same automatically for some endpoints, but this
module allows to check manually the matching results.


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

- From the zip

Download the last release [Urify.zip] from the list of releases, and
uncompress it in the `modules` directory.

- From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Urify`.

Then install it like any other Omeka module and follow the config instructions.


Usage
-----

Go to the page Urify via the link in the left sidebar, fill the form and check
the results.


TODO
----

- [ ] Don't limit to IdRef, but to all ValueSuggest data.
- [ ] Implement other modes of Bulk Edit: reset and re-fill labels and uris.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

```sh
# database dump example
mysqldump -u omeka -p omeka | gzip > "omeka.$(date +%Y%m%d_%H%M%S).sql.gz"
```


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2025 (see [Daniel-KM] on GitLab)

This module was designed for the [digital library Manioc] of the [Université des Antilles et de la Guyane].


[Urify]: https://gitlab.com/Daniel-KM/Omeka-S-module-Urify
[Omeka S]: https://omeka.org/s
[IdRef]: https://www.idref.fr
[Value Suggest]: https://omeka.org/s/modules/ValueSuggest
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[Urify.zip]: https://github.com/Daniel-KM/Omeka-S-module-Urify/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Urify/issues
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[digital library Manioc]: http://www.manioc.org
[Université des Antilles et de la Guyane]: http://www.univ-ag.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
