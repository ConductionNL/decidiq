import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Meetings, agendas & minutes',
    description: (
      <>
        Schedule meetings, build agendas, capture decisions, and publish
        minutes — all as typed OpenRegister objects with a per-record audit
        trail. Configurable workflows per organisation type across five
        governance domains.
      </>
    ),
  },
  {
    title: 'Motions, amendments & voting',
    description: (
      <>
        Submit motions, propose amendments, and run votes with chair
        controls, configurable quorum and voting-rights rules, and a clear
        trail from proposal to adopted decision. Co-authoring, delegation,
        and engagement tracking included.
      </>
    ),
  },
  {
    title: 'Decision tracking & AI companion',
    description: (
      <>
        Follow decisions and action items through to completion, and ask
        the built-in AI chat companion about a meeting, an action item, or
        an open decision — it exposes meeting and action-item tools over
        MCP so the assistant can answer with live data.
      </>
    ),
  },
];

function Feature({ title, description }) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
