import '../../Private/CSS/find.css';
import 'choices.js/public/assets/styles/choices.min.css';
import 'awesomplete/awesomplete.css';
import Awesomplete from "awesomplete";
import Choices from "choices.js";
import Chart from "chart.js/auto";

// Helper short-hands
const qs = (sel, ctx = document) => ctx.querySelector(sel);
const qsa = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel, ctx));

// Container selector: change if your wrapper class changes
const container = qs('.tx_find');

const URLParameterPrefix = "tx_find_find";

function addURLParameter(url, name, value) {
  const [base, hash = ""] = url.split("#");
  const paramStr = `${encodeURIComponent(name)}=${encodeURIComponent(value)}`;
  return `${base}${base.includes("?") ? "&" : "?"}${paramStr}${hash ? "#" + hash : ""}`;
}
function removeURLParameter(url, name) {
  const param = encodeURIComponent(name);
  return url.replace(new RegExp('&?' + param + '=[^&]*'), "").replace(/\?$/, "");
}
function changeURL(url) {
  history.pushState?.(null, '', url);
}
function changeURLParameterForPage(name, value) {
  const paramName = `${URLParameterPrefix}[${name}]`;
  let newURL = removeURLParameter(location.href, paramName);
  if (value !== undefined) newURL = addURLParameter(newURL, paramName, value);
  changeURL(newURL);
  qsa('a:not(.no-change)', container).forEach(a => {
    if (value !== undefined) a.href = addURLParameter(a.href, paramName, value);
    else a.href = removeURLParameter(a.href, paramName);
  });
  qsa(`input.${paramName}`, container).forEach(input => {
    input.name = value !== undefined ? paramName : "";
  });
}

// --- Initialize Awesomplete Autocomplete fields ---
function initAutocompleteFields() {
  qsa('input[autocompleteURL]', container)
    .forEach(input => {
      // Placeholder: real implementation needs your backend's endpoint format
      input.addEventListener("input", async (e) => {
        const term = input.value.trim().toLowerCase();
        if (!term) return;
        let url = input.getAttribute("autocompleteURL").replace('%25%25%25%25', encodeURIComponent(term));
        // Fetch suggestions
        const data = await fetch(url).then(r => r.json()).catch(() => []);
        // Awesomplete expects `list` to be set
        if (!input.awesomplete) input.awesomplete = new Awesomplete(input);
        input.awesomplete.list = data;
      });
    });
}

// --- Initialize Choices.js facet selects ---
function initFacetSearches() {
  qsa(".facetSearch", container)
    .forEach(select => {
      const choices = new Choices(select, {
        searchEnabled: true,
        shouldSort: false
        // ...add more options as needed
      });
      select.addEventListener('change', () => {
        const selected = select.value;
        const li = container.querySelector(`li[value='${selected}']`);
        if (li) li.querySelector('a')?.click();
      });
    });
}

// --- Toggle extended search panel ---
function toggleExtendedSearch(e) {
  const form = qs(".searchForm", container);
  const link = e.target;
  const isExtended = form.classList.contains("search-extended");
  const extStr = link.getAttribute('extendedstring');
  const simpStr = link.getAttribute('simplestring');
  if (!isExtended) {
    link.textContent = extStr;
    qsa(".field-mode-extended", form).forEach(f => (f.style.display = ""));
    changeURLParameterForPage('extended', 1);
  } else {
    link.textContent = simpStr;
    qsa(".field-mode-extended", form).forEach(f => (f.style.display = "none"));
    changeURLParameterForPage('extended');
  }
  form.classList.toggle("search-simple");
  form.classList.toggle("search-extended");
  e.preventDefault();
}

// --- Histogram using Chart.js ---
// Expect each histogram container to have the necessary dataset attributes e.g. data-facet-config, data-link
function initHistogramFacets() {
  qsa(".facetHistogram-container .histogram", container).forEach(hist => {
    // Parse facet config from dataset
    let facetConfig = JSON.parse(hist.dataset.facetConfig);
    const {data: terms, barWidth} = facetConfig;
    const labels = Object.keys(terms).map(Number).sort((a, b) => a - b);
    const values = labels.map(y => terms[y]);

    const ctx = document.createElement('canvas');
    hist.appendChild(ctx);
    let chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Histogram',
          data: values,
          backgroundColor: "#8884d8"
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false }},
        scales: {
          x: { title: { display: true, text: 'Value' }},
          y: { title: { display: true, text: 'Hits' }, beginAtZero: true }
        },
        onClick: (evt, elements) => {
          if (elements.length > 0) {
            const i = elements[0].index;
            const year = labels[i];
            // In your implementation, select a facet and do a search here
            // For demo: construct the RANGE string as in your original
            const range = `RANGE ${year} TO ${year + barWidth - 1}`;
            const linkTemplate = hist.dataset.link;
            if (linkTemplate) {
              location.href = linkTemplate.replace('%25%25%25%25', encodeURIComponent(range));
            }
          }
        },
        // Add hover tooltip as needed (Chart.js does this by default)
      }
    });
  });
}

// --- Main initialization ---
document.addEventListener('DOMContentLoaded', () => {
  if (!container) return;
  initAutocompleteFields();
  initFacetSearches();
  initHistogramFacets();

  // Toggle extended search
  qsa('a.extendedSearch', container).forEach(link =>
    link.addEventListener('click', toggleExtendedSearch)
  );
});

// --- Show/hide overflow for facets ("Show all"/"Hide" links) ---
function showAllFacetsOfType(e) {
  const containingList = e.target.closest('ol');
  const linkShowAll = qs('.facetShowAll', containingList);
  const linkHideHidden = qs('.facetHideHidden', containingList);
  qsa('.hidden', containingList).forEach(el => {
    el.style.display = (el.style.display === 'none' || getComputedStyle(el).display === 'none') ? '' : 'none';
  });
  if (linkShowAll.style.display === 'none' || getComputedStyle(linkShowAll).display === 'none') {
    linkShowAll.style.display = '';
    linkHideHidden.style.display = 'none';
  } else {
    linkShowAll.style.display = 'none';
    linkHideHidden.style.display = '';
  }
  e.preventDefault();
}

// --- Paging handler for detail link POST ---
export function detailViewWithPaging(element, position) {
  const underlyingQuery = window.underlyingQuery;
  function inputWithNameAndValue(name, value) {
    const input = document.createElement('input');
    input.name = name;
    input.value = value;
    input.type = 'hidden';
    return input;
  }
  function inputsWithPrefixForObject(prefix, obj) {
    return Object.entries(obj).flatMap(([k, v]) =>
      (typeof v === 'object')
        ? inputsWithPrefixForObject(`${prefix}[${k}]`, v)
        : [inputWithNameAndValue(`${prefix}[${k}]`, v)]
    );
  }
  if (underlyingQuery) {
    const li = element.closest('li');
    const ol = li?.closest('ol');
    underlyingQuery.position = position ?? (ol ? (+ol.getAttribute('start') + [...ol.children].indexOf(li)) : undefined);
    const form = document.createElement('form');
    form.action = element.getAttribute('href');
    form.method = 'POST';
    form.style.display = 'none';
    document.body.appendChild(form);
    inputsWithPrefixForObject(URLParameterPrefix + '[underlyingQuery]', underlyingQuery)
      .forEach(input => form.appendChild(input));
    if (qs('.searchForm.search-extended', container))
      form.appendChild(inputWithNameAndValue(`${URLParameterPrefix}[extended]`, '1'));
    form.submit();
    return false;
  }
  return true;
}

// --- Optionally export functionality for use elsewhere ---
export default {
  showAllFacetsOfType,
  changeURLParameterForPage,
  detailViewWithPaging
};
