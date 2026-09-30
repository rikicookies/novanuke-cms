(() => {
  const reading = document.querySelector('[data-wiki-reading]');
  const toc = document.querySelector('[data-wiki-toc]');
  if (!reading || !toc) return;
  const headings = [...reading.querySelectorAll('h2, h3')];
  if (!headings.length) {
    const li = document.createElement('li');
    li.className = 'nl-wiki-toc-empty';
    li.textContent = toc.dataset.emptyLabel || '';
    toc.appendChild(li);
    return;
  }
  const used = new Set();
  const slug = (value) => {
    let id = value.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section';
    let candidate = id, i = 2;
    while (used.has(candidate) || document.getElementById(candidate)) candidate = `${id}-${i++}`;
    used.add(candidate); return candidate;
  };
  const links = [];
  headings.forEach((heading) => {
    if (!heading.id) heading.id = slug(heading.textContent.trim()); else used.add(heading.id);
    const li = document.createElement('li');
    if (heading.tagName === 'H3') li.className = 'nl-toc-h3';
    const a = document.createElement('a');
    a.href = `#${heading.id}`; a.textContent = heading.textContent.trim();
    li.appendChild(a); toc.appendChild(li); links.push([heading, a]);
  });
  if (!('IntersectionObserver' in window)) return;
  const observer = new IntersectionObserver((entries) => {
    const visible = entries.filter(e => e.isIntersecting).sort((a,b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
    if (!visible) return;
    links.forEach(([h,a]) => a.classList.toggle('is-active', h === visible.target));
  }, {rootMargin: '-15% 0px -70% 0px', threshold: 0});
  links.forEach(([h]) => observer.observe(h));
})();
