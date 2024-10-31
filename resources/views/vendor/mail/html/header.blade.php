@props(['url'])
<tr>
<td class="header">
<a href="https://www.bricocanali.it" style="display: inline-block;">
<img src=" data:image/png;base64,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" width="40%" alt="Bricocanali-logo">
</a>
</td>
</tr>