# Third-party notices

OpenAP distributes the following third-party browser assets:

| Component | Bundled version | License | Upstream | License file |
| --- | --- | --- | --- | --- |
| Bootstrap | 5.3.3 | MIT | <https://getbootstrap.com/> | `third_party/licenses/bootstrap-5.3.3-LICENSE.txt` |
| Font Awesome Free | 6.6.0 | Icons: CC BY 4.0; fonts: SIL OFL 1.1; code: MIT | <https://fontawesome.com/> | `dist/font-awesome/LICENSE.txt` |
| jQuery | 3.4.1 | MIT | <https://jquery.com/> | `third_party/licenses/jquery-3.4.1-LICENSE.txt` |
| jQuery Mask Plugin | 1.14.16 | MIT | <https://github.com/igorescobar/jQuery-Mask-Plugin> | `third_party/licenses/jquery-mask-1.14.16-LICENSE.txt` |
| SB Admin | 7.0.7 | MIT | <https://github.com/StartBootstrap/startbootstrap-sb-admin> | `third_party/licenses/sb-admin-7.0.7-LICENSE.txt` |

The bundled SB Admin stylesheet also contains Bootstrap 5.2.3 code under the
MIT license; its license is retained at
`third_party/licenses/bootstrap-5.2.3-LICENSE.txt`. Copyright and license
declarations are also preserved in the source headers of the distributed
files.

Chart.js, DataTables, Huebee and jQuery Easing were inherited from the upstream
tree but had no callers in the supported OpenAP interface. They were removed
from the publication candidate rather than declared as runtime dependencies.
